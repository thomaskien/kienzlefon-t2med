#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Kienzlefon\Config;
use Kienzlefon\Runtime;
use Kienzlefon\HttpApi;
use Kienzlefon\Version;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Socket\SocketServer;

$options = getopt('', ['config:', 'demo', 'listen:', 'check-config', 'help', 'version']);
if (isset($options['version'])) { echo 'kienzlefon-t2med ' . Version::CURRENT . "\n"; exit(0); }
if (isset($options['help'])) { echo "kienzlefon-t2med\n  --config=/etc/kienzlefon-t2med/config.toml\n  --check-config   Nur Konfiguration prüfen\n  --version        Programmversion anzeigen\n  --demo           Fiktive Daten, kein AMI/FHIR/APS/GDT\n  --listen=127.0.0.1:8765\n"; exit(0); }
try {
    $demo = isset($options['demo']);
    if ($demo) {
        $config = Config::read(dirname(__DIR__) . '/config.example.toml');
        $config['aps']['username'] = 'demo';
        $config['http']['listen'] = $options['listen'] ?? '127.0.0.1:8765';
        $config['http']['public_url'] = 'http://' . $config['http']['listen'];
        $config['http']['allowed_hosts'][] = parse_url($config['http']['public_url'], PHP_URL_HOST);
        $config = Config::validate($config);
    } else $config = Config::load($options['config'] ?? Config::defaultPath());
    if (isset($options['listen']) && !$demo) { $config['http']['listen'] = $options['listen']; $config = Config::validate($config); }
    if (isset($options['check-config'])) { echo "Konfiguration gültig.\n"; exit(0); }
    $runtime = new Runtime($config, $demo);
    $server = new HttpServer(new StreamingRequestMiddleware(), new LimitConcurrentRequestsMiddleware(64), new RequestBodyBufferMiddleware(16384), new HttpApi($runtime));
    $server->on('error', static function () { fwrite(STDERR, "HTTP-Anfrage konnte nicht verarbeitet werden.\n"); });
    $socket = new SocketServer($config['http']['listen']);
    $server->listen($socket);
    $runtime->start();
    if (function_exists('pcntl_signal')) foreach ([SIGINT,SIGTERM] as $signal) Loop::addSignal($signal, function () use ($runtime,$socket) { $runtime->stop(); $socket->close(); Loop::stop(); });
    echo 'kienzlefon-t2med ' . Version::CURRENT . ($demo ? ' DEMO' : '') . ': ' . $config['http']['public_url'] . "\n";
    Loop::run();
} catch (Throwable $e) { fwrite(STDERR, 'Start fehlgeschlagen: ' . $e->getMessage() . "\n"); exit(1); }
