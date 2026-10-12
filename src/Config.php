<?php
declare(strict_types=1);
namespace Kienzlefon;

use Devium\Toml\Toml;

final class Config
{
    public static function load(string $path): array
    {
        return self::validate(self::read($path));
    }

    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Konfiguration nicht lesbar: ' . $path);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Konfiguration nicht lesbar: ' . $path);
        }
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['toml', 'json'], true)) {
            throw new \InvalidArgumentException('Konfigurationsformat muss TOML oder JSON sein.');
        }
        try {
            $data = match ($extension) {
                'toml' => Toml::decode($raw, asArray: true),
                'json' => json_decode($raw, true, 64, JSON_THROW_ON_ERROR),
            };
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                $extension === 'json' ? 'Ungültige JSON-Konfiguration.' : 'Ungültige TOML-Konfiguration.'
            );
        }
        if (!is_array($data)) throw new \InvalidArgumentException('Konfiguration muss ein Objekt sein.');
        return $data;
    }

    public static function encodeToml(array $config): string
    {
        try {
            $encoded = Toml::encode($config);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Konfiguration kann nicht als TOML serialisiert werden.');
        }
        return rtrim($encoded, "\r\n") . "\n";
    }

    public static function defaultPath(): string
    {
        $toml = '/etc/kienzlefon-t2med/config.toml';
        $json = '/etc/kienzlefon-t2med/config.json';
        return !file_exists($toml) && !is_link($toml) && is_file($json) ? $json : $toml;
    }

    public static function validate(array $c): array
    {
        $defaults = json_decode(file_get_contents(dirname(__DIR__) . '/config.example.json'), true, 64, JSON_THROW_ON_ERROR);
        if (array_diff(array_keys($c), array_keys($defaults))) throw new \InvalidArgumentException('Unbekannter Konfigurationsbereich.');
        foreach ($defaults as $key => $values) {
            if (!isset($c[$key]) || !is_array($c[$key])) throw new \InvalidArgumentException("Konfigurationsbereich fehlt: $key");
            if ($key !== 'clients') {
                if (array_diff(array_keys($c[$key]), array_keys($values))) throw new \InvalidArgumentException("Unbekannte Einstellung in $key");
                $c[$key] = array_replace($values, $c[$key]);
            }
        }
        self::text($c['ami']['host'], 253);
        if (!preg_match('/^[a-zA-Z0-9.\-:]+$/D', $c['ami']['host'])) throw new \InvalidArgumentException('Ungültiger AMI-Host.');
        self::integer($c['ami']['port'], 1, 65535);
        self::text($c['ami']['username'], 128);
        self::text($c['ami']['secret'], 1024, true);
        if (!is_bool($c['ami']['tls']) || !is_bool($c['aps']['tls_verify'])) throw new \InvalidArgumentException('TLS-Schalter müssen boolesch sein.');
        foreach (['ami', 'aps'] as $k) self::text($c[$k]['ca_file'], 2048, true);
        self::url($c['aps']['url'], true);
        self::text($c['aps']['username'], 128);
        if (str_contains($c['aps']['username'], ':')) throw new \InvalidArgumentException('APS-Benutzername enthält Doppelpunkt.');
        self::text($c['aps']['password'], 1024, true);
        self::integer($c['aps']['timeout_seconds'], 1, 30);
        self::integer($c['aps']['cache_seconds'], 0, 3600);
        self::url($c['http']['public_url']);
        if (!is_string($c['http']['listen']) || !preg_match('/^(?:[a-zA-Z0-9.\-]+|\[[0-9a-fA-F:]+\]):([0-9]{1,5})$/D', $c['http']['listen'], $m) || (int)$m[1] < 1 || (int)$m[1] > 65535) throw new \InvalidArgumentException('Ungültige HTTP-Bindeadresse.');
        foreach (['allowed_hosts','allowed_networks'] as $field) {
            if (!is_array($c['http'][$field]) || !$c['http'][$field]) throw new \InvalidArgumentException("HTTP $field darf nicht leer sein.");
            foreach ($c['http'][$field] as $value) self::text($value, 253);
        }
        foreach ($c['http']['allowed_hosts'] as $host) if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D', $host)) throw new \InvalidArgumentException('Ungültiger erlaubter Host.');
        foreach ($c['http']['allowed_networks'] as $net) {
            $parts = explode('/', $net);
            if (count($parts) !== 2 || @inet_pton($parts[0]) === false || !ctype_digit($parts[1]) || (int)$parts[1] > strlen(inet_pton($parts[0])) * 8) throw new \InvalidArgumentException('Ungültiges Netzwerk.');
        }
        $t = $c['telephony'];
        if (!is_array($t['queues']) || !is_array($t['extensions'])) throw new \InvalidArgumentException('Ungültige Telefoniekonfiguration.');
        foreach ($t['queues'] as $q) self::text($q, 128);
        foreach ($t['extensions'] as $id => $label) { self::extension((string)$id); self::text($label, 128); }
        if (!is_string($t['country_code']) || !preg_match('/^[1-9][0-9]{0,3}$/D', $t['country_code'])) throw new \InvalidArgumentException('Ungültiger Ländercode.');
        if (!is_string($t['originate_context']) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $t['originate_context'])) throw new \InvalidArgumentException('Ungültiger Originate-Kontext.');
        if (!is_string($t['transfer_context']) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $t['transfer_context'])) throw new \InvalidArgumentException('Ungültiger Transfer-Kontext.');
        self::integer($t['originate_timeout_ms'], 1000, 120000);
        self::text($c['gdt']['root'], 2048);
        if (!str_starts_with($c['gdt']['root'], '/') || rtrim($c['gdt']['root'], '/') === '') throw new \InvalidArgumentException('GDT-Root muss ein absolutes Datenverzeichnis sein.');
        if ($c['gdt']['filename'] !== 'AUFRUFi.gdt') throw new \InvalidArgumentException('Importdatei muss AUFRUFi.gdt heißen.');
        foreach (['sender','receiver'] as $k) if (!is_string($c['gdt'][$k]) || !preg_match('/^[A-Za-z0-9_-]{1,8}$/D', $c['gdt'][$k])) throw new \InvalidArgumentException('Ungültige GDT-Kennung.');
        $dirs = [];
        foreach ($c['clients'] as $id => $client) {
            self::clientId((string)$id);
            if (!is_array($client) || array_diff(array_keys($client), ['label','extension','directory'])) throw new \InvalidArgumentException('Ungültiger Arbeitsplatz.');
            self::text($client['label'] ?? null, 128);
            self::extension($client['extension'] ?? '');
            if (!isset($t['extensions'][$client['extension']])) throw new \InvalidArgumentException('Arbeitsplatz-Nebenstelle ist nicht konfiguriert.');
            self::clientId($client['directory'] ?? '');
            if (isset($dirs[$client['directory']])) throw new \InvalidArgumentException('Jeder Arbeitsplatz benötigt ein eigenes GDT-Verzeichnis.');
            $dirs[$client['directory']] = true;
        }
        return $c;
    }

    public static function text(mixed $v, int $max, bool $empty = false): void
    {
        if (!is_string($v) || (!$empty && trim($v) === '') || strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/', $v)) throw new \InvalidArgumentException('Ungültiger Textwert in der Konfiguration.');
    }
    public static function integer(mixed $v, int $min, int $max): void
    {
        if (!is_int($v) || $v < $min || $v > $max) throw new \InvalidArgumentException('Zahl außerhalb des erlaubten Bereichs.');
    }
    public static function clientId(string $v): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $v)) throw new \InvalidArgumentException('Ungültige Arbeitsplatzkennung.');
    }
    public static function extension(string $v): void
    {
        if (!preg_match('/^[0-9]{1,12}$/D', $v)) throw new \InvalidArgumentException('Ungültige Nebenstelle.');
    }
    public static function url(string $v, bool $https = false): void
    {
        self::text($v, 2048);
        $u = parse_url($v);
        if (!$u || !isset($u['host']) || !in_array($u['scheme'] ?? '', $https ? ['https'] : ['http','https'], true) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || !in_array($u['path'] ?? '', ['', '/'], true)) throw new \InvalidArgumentException('Ungültige Server-Basisadresse.');
    }
}
