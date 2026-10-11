<?php
declare(strict_types=1);
namespace Kienzlefon;

use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use function React\Promise\all;
use function React\Promise\resolve;
use function React\Promise\reject;

final class Aps
{
    private Browser $http;
    private array $cache = [];
    private array $pending = [];
    public function __construct(private array $config, private string $country = '49', ?Browser $http = null)
    {
        $tls = ['verify_peer'=>$config['tls_verify'], 'verify_peer_name'=>$config['tls_verify']];
        if ($config['ca_file'] !== '') $tls['cafile'] = $config['ca_file'];
        $this->http = ($http ?? new Browser(new Connector(['tls'=>$tls, 'timeout'=>$config['timeout_seconds']])))
            ->withTimeout($config['timeout_seconds'])->withFollowRedirects(false)->withResponseBuffer(4 * 1024 * 1024);
    }
    public function lookup(string $number): PromiseInterface
    {
        $key = Phone::key($number, $this->country);
        if (isset($this->cache[$key]) && $this->cache[$key]['expires'] > microtime(true)) return resolve($this->cache[$key]['result']);
        if (isset($this->pending[$key])) return $this->pending[$key];
        if (count($this->pending) >= 16) return reject(new \RuntimeException('APS ist ausgelastet. Bitte erneut versuchen.'));
        $variants = Phone::variants($number, $this->country);
        $requests = array_map(fn($q) => $this->search($q), $variants);
        $promise = all($requests)->then(function ($responses) use ($variants, $key) {
            $result = self::classify($responses, $variants);
            if (count($this->cache) >= 512) array_shift($this->cache);
            $this->cache[$key] = ['expires'=>microtime(true) + $this->config['cache_seconds'], 'result'=>$result];
            return $result;
        });
        $this->pending[$key] = $promise;
        $promise->then(function () use ($key) { unset($this->pending[$key]); }, function () use ($key) { unset($this->pending[$key]); });
        return $promise;
    }
    public function search(string $query): PromiseInterface
    {
        return $this->http->post(rtrim($this->config['url'], '/') . '/aps/rest/praxis/patient/suche/find', [
            'Authorization'=>'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['password']),
            'Accept'=>'application/json', 'Content-Type'=>'application/json',
        ], json_encode(['suchmaske'=>$query, 'ignoreSichtbarkeit'=>false], JSON_THROW_ON_ERROR))->then(static function ($response) {
            $rows = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($rows) || !array_is_list($rows)) throw new \RuntimeException('APS liefert keine Trefferliste.');
            foreach ($rows as $row) {
                if (!is_array($row) || !is_int($row['nummer'] ?? null) || $row['nummer'] < 1 || $row['nummer'] > 999999999999 || !is_string($row['ref']['objectId']['id'] ?? null) || $row['ref']['objectId']['id'] === '') throw new \RuntimeException('APS liefert unvollständige Patiententreffer.');
                foreach (['vorname','nachname','geburtsdatum','adresse'] as $f) if (isset($row[$f]) && !is_string($row[$f])) throw new \RuntimeException('APS liefert unerwartete Personendaten.');
            }
            return $rows;
        }, static function (\Throwable $e) {
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out');
            throw new \RuntimeException('APS-Abfrage fehlgeschlagen. Verbindung, Zertifikat und Zugang prüfen.', $timeout ? 408 : 0);
        });
    }
    public static function classify(array $responses, array $queries): array
    {
        $patients = [];
        $collision = false;
        foreach ($responses as $rows) foreach ($rows as $p) {
            $key = $p['nummer'] . ':' . $p['ref']['objectId']['id'];
            $patients[$key] = [
                'patient_number'=>$p['nummer'], 'first_name'=>$p['vorname'] ?? '', 'last_name'=>$p['nachname'] ?? '',
                'birth_date'=>$p['geburtsdatum'] ?? '', 'address'=>$p['adresse'] ?? '', '_ref'=>$p['ref'],
            ];
            foreach ($queries as $q) if (ltrim($q, '+0') === (string)$p['nummer']) $collision = true;
        }
        $patients = array_values($patients);
        $status = !$patients ? 'not_found' : (count($patients) === 1 && !$collision ? 'resolved' : 'ambiguous');
        $name = $status === 'resolved' ? self::displayName($patients[0]) : '';
        if ($status === 'resolved' && $name === '') $status = 'ambiguous';
        return ['status'=>$status, 'name'=>$name, 'patients'=>$patients];
    }
    public static function displayName(array $p): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $p['first_name'] . ' ' . $p['last_name'])), 0, 80);
    }
    public static function publicPatients(array $patients): array
    {
        return array_map(static function ($p) { unset($p['_ref']); return $p; }, $patients);
    }
}
