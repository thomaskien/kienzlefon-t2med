#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Kienzlefon\Config;
use Kienzlefon\Runtime;
use Kienzlefon\HttpApi;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Socket\SocketServer;

$options = getopt('', ['config:', 'demo', 'listen:', 'check-config', 'help']);
if (isset($options['help'])) { echo "kienzlefon-t2med\n  --config=/etc/kienzlefon-t2med/config.json\n  --check-config   Nur Konfiguration prüfen\n  --demo           Fiktive Daten, kein AMI/APS/GDT\n  --listen=127.0.0.1:8765\n"; exit(0); }
try {
    $demo = isset($options['demo']);
    if ($demo) {
        $config = json_decode(file_get_contents(dirname(__DIR__) . '/config.example.json'), true, 64, JSON_THROW_ON_ERROR);
        $config['aps']['username'] = 'demo';
        $config['http']['listen'] = $options['listen'] ?? '127.0.0.1:8765';
        $config['http']['public_url'] = 'http://' . $config['http']['listen'];
        $config['http']['allowed_hosts'][] = parse_url($config['http']['public_url'], PHP_URL_HOST);
        $config = Config::validate($config);
    } else $config = Config::load($options['config'] ?? '/etc/kienzlefon-t2med/config.json');
    if (isset($options['listen']) && !$demo) { $config['http']['listen'] = $options['listen']; $config = Config::validate($config); }
    if (isset($options['check-config'])) { echo "Konfiguration gültig.\n"; exit(0); }
    $runtime = new Runtime($config, $demo);
    $server = new HttpServer(new StreamingRequestMiddleware(), new LimitConcurrentRequestsMiddleware(64), new RequestBodyBufferMiddleware(8192), new HttpApi($runtime));
    $server->on('error', static function () { fwrite(STDERR, "HTTP-Anfrage konnte nicht verarbeitet werden.\n"); });
    $socket = new SocketServer($config['http']['listen']);
    $server->listen($socket);
    $runtime->start();
    if (function_exists('pcntl_signal')) foreach ([SIGINT,SIGTERM] as $signal) Loop::addSignal($signal, function () use ($runtime,$socket) { $runtime->stop(); $socket->close(); Loop::stop(); });
    echo 'kienzlefon-t2med' . ($demo ? ' DEMO' : '') . ': ' . $config['http']['public_url'] . "\n";
    Loop::run();
} catch (Throwable $e) { fwrite(STDERR, 'Start fehlgeschlagen: ' . $e->getMessage() . "\n"); exit(1); }
