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
        // Older files could omit these switches. They previously verified certificates.
        // The installer writes the selected value explicitly for new installations.
        $defaults['aps']['tls_verify'] = true;
        $defaults['fhir']['tls_verify'] = true;
        // Old configurations retain APS credentials, but no longer enable its live search.
        $c['patients'] ??= $defaults['patients'];
        if (!isset($c['fhir'])) {
            $c['fhir'] = $defaults['fhir'];
            if (isset($c['aps']['url'])) $c['fhir']['base_url'] = rtrim($c['aps']['url'], '/') . '/aps/fhir/api/r4';
            foreach (['tls_verify','ca_file'] as $field) if (isset($c['aps'][$field])) $c['fhir'][$field] = $c['aps'][$field];
        }
        $c['aps'] ??= $defaults['aps'];
        $c['call_statistics'] ??= $defaults['call_statistics'];
        $c['telephony_control'] ??= $defaults['telephony_control'];
        $c['app_access'] ??= $defaults['app_access'];
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
        if (!in_array($c['patients']['provider'], ['fhir','aps'], true)) throw new \InvalidArgumentException('Patientenquelle muss fhir oder aps sein.');
        $f = $c['fhir'];
        self::text($f['base_url'], 2048);
        $url = parse_url($f['base_url']);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || preg_match('~[\\\\%]|(?:^|/)\.{1,2}(?:/|$)~', $url['path'] ?? '')) throw new \InvalidArgumentException('Ungültige FHIR-Basisadresse.');
        if (!is_bool($f['tls_verify'])) throw new \InvalidArgumentException('FHIR-TLS-Schalter muss boolesch sein.');
        foreach (['ca_file','local_area_code'] as $field) self::text($f[$field], 2048, true);
        if ($f['local_area_code'] !== '' && !preg_match('/^0[1-9][0-9]{1,5}$/D', $f['local_area_code'])) throw new \InvalidArgumentException('Ortsvorwahl muss eine deutsche Vorwahl mit führender Null sein.');
        foreach (['binary','database'] as $field) {
            self::text($f[$field], 2048);
            if (!str_starts_with($f[$field], '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $f[$field]) || str_ends_with($f[$field], '/')) throw new \InvalidArgumentException('FHIR-Dateipfade müssen absolute Dateipfade sein.');
        }
        foreach ([$c['gdt']['root'] ?? '', dirname(__DIR__) . '/public'] as $unsafe) if ($unsafe !== '' && str_starts_with($f['database'], rtrim($unsafe, '/') . '/')) throw new \InvalidArgumentException('Patientenbestand darf nicht im GDT-Share oder Webverzeichnis liegen.');
        self::integer($f['timeout_seconds'], 1, 120);
        self::integer($f['max_pages'], 1, 100000);
        self::integer($f['max_patients'], 1, 1000000);
        self::integer($f['cache_seconds'], 5, 3600);
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
        if (!is_array($t['inactive_extensions']) || !array_is_list($t['inactive_extensions'])) {
            throw new \InvalidArgumentException('Inaktive Nebenstellen müssen als Liste angegeben sein.');
        }
        foreach ($t['inactive_extensions'] as $number) {
            self::extension($number);
            if (isset($t['extensions'][$number])) throw new \InvalidArgumentException('Nebenstelle ist zugleich aktiv und inaktiv.');
        }
        if (!is_string($t['country_code']) || !preg_match('/^[1-9][0-9]{0,3}$/D', $t['country_code'])) throw new \InvalidArgumentException('Ungültiger Ländercode.');
        if (!is_string($t['originate_context']) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $t['originate_context'])) throw new \InvalidArgumentException('Ungültiger Originate-Kontext.');
        if (!is_string($t['transfer_context']) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $t['transfer_context'])) throw new \InvalidArgumentException('Ungültiger Transfer-Kontext.');
        self::integer($t['originate_timeout_ms'], 1000, 120000);
        self::text($c['gdt']['root'], 2048);
        if (!str_starts_with($c['gdt']['root'], '/') || rtrim($c['gdt']['root'], '/') === '') throw new \InvalidArgumentException('GDT-Root muss ein absolutes Datenverzeichnis sein.');
        $s = $c['call_statistics'];
        if (!is_bool($s['enabled']) || $s['timezone'] !== 'Europe/Berlin') throw new \InvalidArgumentException('Anrufstatistik benötigt enabled=true/false und Europe/Berlin.');
        if (!is_string($s['pbx_id']) || !preg_match('/^[a-zA-Z0-9_.-]{0,64}$/D', $s['pbx_id']) || ($s['enabled'] && $s['pbx_id'] === '')) throw new \InvalidArgumentException('Für die Anrufstatistik ist eine stabile Anlagenkennung erforderlich.');
        self::text($s['database_path'], 2048);
        if (!preg_match('~^/[a-zA-Z0-9_./-]+$~D', $s['database_path']) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $s['database_path']) || str_ends_with($s['database_path'], '/')) throw new \InvalidArgumentException('Statistikpfad muss ein sicherer absoluter Dateipfad sein.');
        $statisticsPath = self::resolvedPath($s['database_path']);
        foreach ([$c['gdt']['root'], dirname(__DIR__), '/opt/kienzlefon-t2med'] as $unsafe) {
            $unsafe = rtrim(self::resolvedPath($unsafe), '/');
            if ($statisticsPath === $unsafe || str_starts_with($statisticsPath, $unsafe . '/')) throw new \InvalidArgumentException('Statistik muss außerhalb von GDT-Share und Anwendungsverzeichnis liegen.');
        }
        $patientPath = self::resolvedPath($f['database']);
        if ($statisticsPath === $patientPath || str_starts_with($statisticsPath, $patientPath . '.') || str_starts_with($statisticsPath, $patientPath . '-')
            || str_starts_with($patientPath, $statisticsPath . '.') || str_starts_with($patientPath, $statisticsPath . '-')) throw new \InvalidArgumentException('Anrufstatistik benötigt eine eigene Datei getrennt vom FHIR-Bestand.');
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
        $control=$c['telephony_control']; $app=$c['app_access'];
        if (!is_bool($control['enabled']) || !is_bool($app['enabled'])) throw new \InvalidArgumentException('Dienstschalter müssen boolesch sein.');
        self::url($control['base_url']);
        self::text($control['pbx_id'],128,true);
        if ($control['enabled'] && !TelephonyContract::valid('PbxId',$control['pbx_id'])) throw new \InvalidArgumentException('Feste Anlagenkennung erforderlich.');
        if (!is_array($app['allowed_clients']) || !array_is_list($app['allowed_clients']) || !is_array($app['allowed_networks']) || !array_is_list($app['allowed_networks']) || ($app['enabled'] && !$app['allowed_networks'])) throw new \InvalidArgumentException('App-Clientliste und erlaubtes Quellnetz erforderlich.');
        if (!in_array($app['workplace_mode'], ['fon','selected'], true) || !is_bool($control['legacy_routing']) ||
            !is_string($control['routing_token_file']) || ($control['legacy_routing'] && $control['routing_token_file'] === '')) {
            throw new \InvalidArgumentException('Ungültige Rufgruppenkopplung oder Arbeitsplatzbeschränkung.');
        }
        foreach ($app['allowed_clients'] as $id) {
            if (!TelephonyContract::valid('ClientId', $id)) throw new \InvalidArgumentException('Ungültige App-Arbeitsplatzkennung.');
        }
        foreach ($app['allowed_networks'] as $net) {
            if (!is_string($net)) throw new \InvalidArgumentException('Ungültiges App-Netz.');
            $parts=explode('/',$net);
            if (count($parts)!==2 || @inet_pton($parts[0])===false || !ctype_digit($parts[1]) || (int)$parts[1]>strlen(inet_pton($parts[0]))*8) throw new \InvalidArgumentException('Ungültiges App-Netz.');
        }
        if ($control['enabled'] && $s['enabled'] && $control['pbx_id']!==$s['pbx_id']) throw new \InvalidArgumentException('Steuerung und Statistik müssen dieselbe Anlagenkennung verwenden.');
        $paths=[$control['token_file'],$app['token_file'],$control['request_database']];
        if ($control['routing_token_file'] !== '') $paths[] = $control['routing_token_file'];
        foreach ($paths as $path) {
            self::text($path,2048);
            if (!preg_match('~^/[a-zA-Z0-9_./-]+$~D',$path) || str_ends_with($path,'/') || preg_match('~(?:^|/)\\.{1,2}(?:/|$)~',$path) || is_link($path)) throw new \InvalidArgumentException('Dienstdaten benötigen absolute eigene Dateipfade.');
            foreach ([$c['gdt']['root'],dirname(__DIR__),'/opt/kienzlefon-t2med'] as $unsafe) if (str_starts_with(self::resolvedPath($path).'/',rtrim(self::resolvedPath($unsafe),'/').'/')) throw new \InvalidArgumentException('Dienstdaten dürfen nicht im Programmverzeichnis oder GDT-Share liegen.');
        }
        $paths=array_map(self::resolvedPath(...),array_merge($paths,[$c['fhir']['database'],$c['call_statistics']['database_path']]));
        foreach($paths as $i=>$a) foreach($paths as $j=>$b) if($i!==$j && ($a===$b || str_starts_with($a,$b.'-') || str_starts_with($a,$b.'.'))) throw new \InvalidArgumentException('Schlüssel und Datenbanken benötigen getrennte Dateien.');
        return $c;
    }

    public static function checkServiceKeys(array $config): void
    {
        $keys=[];
        foreach (['telephony_control','app_access'] as $section) if ($config[$section]['enabled']) {
            try { $keys[]=TelephonyContract::secret($config[$section]['token_file']); }
            catch (\Throwable) { throw new \InvalidArgumentException('Dienstschlüssel für '.$section.' fehlt, ist nicht lesbar oder nicht mit Modus 0600 geschützt.'); }
        }
        $routing = $config['telephony_control']['routing_token_file'] ?? '';
        if ($config['telephony_control']['enabled'] && $routing !== '') {
            try { $keys[] = TelephonyContract::secret($routing); }
            catch (\Throwable) { throw new \InvalidArgumentException('Der private Rufgruppenschlüssel fehlt oder benötigt Modus 0600.'); }
        }
        if (count($keys) !== count(array_unique($keys)) || in_array($config['ami']['secret'], $keys, true)) {
            throw new \InvalidArgumentException('App, AMI, Lese- und Rufgruppenzugänge benötigen getrennte Schlüssel.');
        }
    }
    private static function resolvedPath(string $path): string
    {
        $tail = [];
        while (realpath($path) === false && $path !== '/') { array_unshift($tail, basename($path)); $path = dirname($path); }
        return rtrim(realpath($path) ?: $path, '/') . ($tail ? '/' . implode('/', $tail) : '');
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
