<?php
declare(strict_types=1);
namespace Kienzlefon;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use function React\Promise\reject;

final class Ami extends EventEmitter
{
    private ?ConnectionInterface $stream = null;
    private array $pending = [];
    private string $buffer = '';
    private bool $running = false;
    private bool $ready = false;
    private int $sequence = 0;
    private int $generation = 0;
    private float $backoff = 1;
    private mixed $retry = null;
    private mixed $heartbeat = null;
    public function __construct(private array $config) {}
    public function connected(): bool { return $this->ready; }
    public function start(): void { $this->running = true; $this->connect(); }
    public function stop(): void
    {
        $this->running = false;
        if ($this->retry) Loop::cancelTimer($this->retry);
        $this->disconnect();
    }
    private function connect(): void
    {
        if (!$this->running) return;
        $generation = ++$this->generation;
        $tls = ['verify_peer'=>true, 'verify_peer_name'=>true];
        if ($this->config['ca_file'] !== '') $tls['cafile'] = $this->config['ca_file'];
        $connector = new Connector(['timeout'=>5, 'tls'=>$tls]);
        $host = str_contains($this->config['host'], ':') ? '[' . $this->config['host'] . ']' : $this->config['host'];
        $connector->connect(($this->config['tls'] ? 'tls' : 'tcp') . '://' . $host . ':' . $this->config['port'])->then(function ($stream) use ($generation) {
            if (!$this->running || $generation !== $this->generation) { $stream->close(); return; }
            $this->stream = $stream;
            $this->buffer = '';
            $stream->on('data', function ($data) {
                $this->buffer .= $data;
                if (strlen($this->buffer) > 4 * 1024 * 1024) { $this->disconnect(); return; }
                while (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {
                    $frame = substr($this->buffer, 0, $pos);
                    $this->buffer = substr($this->buffer, $pos + 4);
                    $this->receive(self::parse($frame));
                }
            });
            $stream->on('error', fn() => $this->disconnect());
            $stream->on('close', function () use ($stream) { if ($this->stream === $stream) $this->disconnect(); });
            $this->action('Login', ['Username'=>$this->config['username'], 'Secret'=>$this->config['secret'], 'Events'=>'on'])->then(function () {
                if (!$this->stream) return;
                $this->ready = true;
                $this->backoff = 1;
                $this->emit('connected');
                $this->heartbeat = Loop::addPeriodicTimer(10, function () {
                    $this->action('Ping')->then(null, fn() => $this->disconnect());
                });
            }, fn() => $this->disconnect());
        }, function () use ($generation) { if ($generation === $this->generation) $this->disconnect(); });
    }
    public function disconnect(): void
    {
        $stream = $this->stream;
        $this->stream = null;
        $this->ready = false;
        if ($this->heartbeat) Loop::cancelTimer($this->heartbeat);
        $this->heartbeat = null;
        foreach ($this->pending as $entry) {
            Loop::cancelTimer($entry['timer']);
            $entry['deferred']->reject(new \RuntimeException('AMI-Verbindung unterbrochen.'));
        }
        $this->pending = [];
        if ($stream) $stream->close();
        $this->emit('disconnected');
        if ($this->running && !$this->retry) {
            $this->retry = Loop::addTimer($this->backoff, function () { $this->retry = null; $this->connect(); });
            $this->backoff = min(30, $this->backoff * 2);
        }
    }
    public function action(string $action, array $fields = [], ?string $complete = null, ?string $id = null): PromiseInterface
    {
        if (!$this->stream || (!$this->ready && $action !== 'Login')) return reject(new \RuntimeException('AMI ist nicht verbunden.'));
        if (!in_array($action, ['Login','Logoff','Ping','CoreShowChannels','QueueStatus','Setvar','Originate'], true)) return reject(new \InvalidArgumentException('AMI-Aktion nicht erlaubt.'));
        $id ??= 'kf-' . ++$this->sequence;
        if (isset($this->pending[$id])) return reject(new \RuntimeException('Doppelte AMI-Auftragskennung.'));
        try { $wire = self::encode(['Action'=>$action, 'ActionID'=>$id] + $fields); }
        catch (\Throwable $e) { return reject($e); }
        $d = new Deferred();
        $timer = Loop::addTimer($complete ? 12 : 5, function () use ($id, $d) {
            unset($this->pending[$id]);
            $d->reject(new \RuntimeException('AMI-Antwortzeit überschritten.'));
        });
        $this->pending[$id] = ['deferred'=>$d, 'timer'=>$timer, 'complete'=>$complete, 'events'=>[]];
        $this->stream->write($wire);
        return $d->promise();
    }
    private function receive(array $frame): void
    {
        if (!$frame) return;
        $id = $frame['ActionID'] ?? '';
        if (isset($this->pending[$id])) {
            $p = &$this->pending[$id];
            if (($frame['Response'] ?? '') === 'Error') {
                $entry = $p; unset($this->pending[$id]);
                Loop::cancelTimer($entry['timer']);
                $entry['deferred']->reject(new \RuntimeException('AMI-Aktion abgelehnt. Rechte und Dialplan prüfen.'));
            } elseif (($p['complete'] && ($frame['Event'] ?? '') === $p['complete']) || (!$p['complete'] && isset($frame['Response']))) {
                $entry = $p; unset($this->pending[$id]);
                Loop::cancelTimer($entry['timer']);
                $entry['deferred']->resolve($entry['complete'] ? $entry['events'] : $frame);
            } elseif ($p['complete'] && isset($frame['Event'])) {
                if (count($p['events']) >= 10000) { unset($p); $this->disconnect(); return; }
                $p['events'][] = $frame;
            }
            unset($p);
        }
        if (isset($frame['Event'])) $this->emit('event', [$frame]);
    }
    public static function parse(string $frame): array
    {
        $out = [];
        foreach (explode("\r\n", $frame) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $key = substr($line, 0, $pos);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $key)) continue;
            $out[$key] = ltrim(substr($line, $pos + 1));
        }
        return $out;
    }
    public static function encode(array $fields): string
    {
        $wire = '';
        foreach ($fields as $key => $values) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $key)) throw new \InvalidArgumentException('Ungültiges AMI-Feld.');
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_scalar($value) || preg_match('/[\r\n\x00]/', (string)$value)) throw new \InvalidArgumentException('Ungültiger AMI-Wert.');
                $wire .= $key . ': ' . $value . "\r\n";
            }
        }
        return $wire . "\r\n";
    }
}
