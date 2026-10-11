<?php
declare(strict_types=1);

namespace Kienzlefon\Installer;

require_once dirname(__DIR__) . '/src/Config.php';

final class ConfigurationError extends \RuntimeException {}

/** @return array<string,mixed> */
function loadJsonFile(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new ConfigurationError("Konfiguration kann nicht gelesen werden: {$path}");
    }
    try {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new ConfigurationError('Ungültiges JSON: ' . $e->getMessage());
    }
    if (!is_array($value)) {
        throw new ConfigurationError('Die Konfiguration muss ein JSON-Objekt sein.');
    }
    return $value;
}

function text(mixed $value, string $name, bool $allowEmpty = false): string
{
    if (!is_string($value) || (!$allowEmpty && $value === '') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new ConfigurationError("Ungültiger Wert für {$name}.");
    }
    return $value;
}

function host(string $value, string $name): string
{
    text($value, $name);
    if (strlen($value) > 253 || !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\]|[0-9A-Fa-f:]+)$/', $value)) {
        throw new ConfigurationError("Ungültiger Host für {$name}.");
    }
    return $value;
}

function port(mixed $value, string $name): int
{
    if (is_string($value) && ctype_digit($value)) {
        $value = (int)$value;
    }
    if (!is_int($value) || $value < 1 || $value > 65535) {
        throw new ConfigurationError("{$name} muss zwischen 1 und 65535 liegen.");
    }
    return $value;
}

function boolean(mixed $value, string $name): bool
{
    if (!is_bool($value)) {
        throw new ConfigurationError("{$name} muss true oder false sein.");
    }
    return $value;
}

function absolutePath(mixed $value, string $name, bool $allowEmpty = false): string
{
    $path = text($value, $name, $allowEmpty);
    if ($path === '' && $allowEmpty) {
        return '';
    }
    if ($path[0] !== '/' || str_contains($path, '..') || !preg_match('#^/[A-Za-z0-9._/-]+$#', $path)) {
        throw new ConfigurationError("{$name} muss ein sicherer absoluter Pfad sein.");
    }
    return rtrim($path, '/') ?: '/';
}

function webUrl(mixed $value, string $name): string
{
    $url = text($value, $name);
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new ConfigurationError("{$name} muss eine HTTP(S)-URL ohne Zugangsdaten, Query oder Fragment sein.");
    }
    host((string)$parts['host'], $name . ' Host');
    if (isset($parts['port'])) {
        port($parts['port'], $name . ' Port');
    }
    $path = $parts['path'] ?? '';
    if ($path !== '' && $path !== '/') {
        throw new ConfigurationError("{$name} darf keinen Pfad enthalten.");
    }
    return rtrim($url, '/');
}

/** @param array<string,mixed> $answers @param array<string,mixed>|null $existing @return array<string,mixed> */
function buildConfig(array $answers, ?array $existing = null): array
{
    $required = ['public_url', 'listen_port', 'aps_host', 'aps_port', 'aps_username', 'aps_tls', 'aps_tls_verify', 'ami_host', 'ami_port', 'ami_username', 'ami_tls', 'gdt_root', 'clients'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $answers)) {
            throw new ConfigurationError("Eingabe fehlt: {$key}");
        }
    }

    $publicUrl = webUrl($answers['public_url'], 'Middleware publicURL');
    $public = parse_url($publicUrl);
    $listenPort = port($answers['listen_port'], 'Middleware-Listen-Port');
    if ($listenPort < 1024) {
        throw new ConfigurationError('Der unprivilegierte Dienst benötigt einen Listen-Port ab 1024.');
    }
    $apsHost = host((string)$answers['aps_host'], 'APS-Host');
    $apsPort = port($answers['aps_port'], 'APS-Port');
    $amiHost = host((string)$answers['ami_host'], 'AMI-Host');
    $amiPort = port($answers['ami_port'], 'AMI-Port');
    $apsTls = boolean($answers['aps_tls'], 'APS-TLS');
    if (!$apsTls) throw new ConfigurationError('APS benötigt HTTPS; bei Bedarf die Zertifikatsausnahme wählen.');

    $apsPassword = array_key_exists('aps_password', $answers)
        ? text($answers['aps_password'], 'APS-Passwort', true)
        : (string)($existing['aps']['password'] ?? '');
    $amiSecret = array_key_exists('ami_secret', $answers)
        ? text($answers['ami_secret'], 'AMI-Secret', true)
        : (string)($existing['ami']['secret'] ?? '');

    if (!is_array($answers['clients']) || $answers['clients'] === []) {
        throw new ConfigurationError('Mindestens ein Client ist erforderlich.');
    }
    $clients = [];
    $extensions = [];
    $directories = [];
    foreach ($answers['clients'] as $id => $client) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) || !is_array($client)) {
            throw new ConfigurationError('Ungültige Client-ID. Erlaubt sind Kleinbuchstaben, Ziffern, _ und -.');
        }
        $label = text($client['label'] ?? null, "Label für {$id}");
        $extension = text($client['extension'] ?? null, "Nebenstelle für {$id}");
        if (!preg_match('/^[0-9]{1,16}$/', $extension)) {
            throw new ConfigurationError("Ungültige Nebenstelle für {$id}.");
        }
        $directory = text($client['directory'] ?? null, "Verzeichnis für {$id}");
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $directory) || $directory === '.' || isset($directories[$directory])) {
            throw new ConfigurationError("Ungültiges oder doppeltes Client-Verzeichnis für {$id}.");
        }
        $directories[$directory] = true;
        $clients[$id] = ['label' => $label, 'extension' => $extension, 'directory' => $directory];
        $extensions[$extension] = $label;
    }

    $allowedHosts = ['localhost', '127.0.0.1'];
    $urlHost = (string)$public['host'];
    if (!in_array($urlHost, $allowedHosts, true)) {
        $allowedHosts[] = $urlHost;
    }

    $gdtRoot = absolutePath($answers['gdt_root'], 'GDT-Root');
    if ($gdtRoot === '/') {
        throw new ConfigurationError('Der GDT-Root darf nicht das Dateisystem-Wurzelverzeichnis sein.');
    }

    $config = [
        'http' => [
            'listen' => '0.0.0.0:' . $listenPort,
            'public_url' => $publicUrl,
            'allowed_hosts' => $allowedHosts,
            'allowed_networks' => ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'],
        ],
        'ami' => [
            'host' => $amiHost,
            'port' => $amiPort,
            'username' => text($answers['ami_username'], 'AMI-Benutzer'),
            'secret' => $amiSecret,
            'tls' => boolean($answers['ami_tls'], 'AMI-TLS'),
            'ca_file' => isset($answers['ami_ca_file']) ? absolutePath($answers['ami_ca_file'], 'AMI-CA-Datei', true) : '',
        ],
        'aps' => [
            'url' => ($apsTls ? 'https' : 'http') . '://' . (str_contains($apsHost, ':') && $apsHost[0] !== '[' ? "[{$apsHost}]" : $apsHost) . ':' . $apsPort,
            'username' => text($answers['aps_username'], 'APS-Benutzer', true),
            'password' => $apsPassword,
            'tls_verify' => boolean($answers['aps_tls_verify'], 'APS-TLS-Prüfung'),
            'ca_file' => isset($answers['aps_ca_file']) ? absolutePath($answers['aps_ca_file'], 'APS-CA-Datei', true) : '',
            'timeout_seconds' => 4,
            'cache_seconds' => 120,
        ],
        'telephony' => [
            'queues' => [],
            'extensions' => $extensions,
            'country_code' => '49',
            'originate_context' => 'kienzlefon-click-to-dial',
            'originate_timeout_ms' => 20000,
        ],
        'gdt' => [
            'root' => $gdtRoot,
            'filename' => 'AUFRUFi.gdt',
            'sender' => 'AUFRUF',
            'receiver' => 'T2MED_PX',
        ],
        'clients' => $clients,
    ];
    if ($existing !== null) {
        $config['http']['allowed_networks'] = $existing['http']['allowed_networks'];
        $config['http']['allowed_hosts'] = array_values(array_unique(array_merge($existing['http']['allowed_hosts'], $config['http']['allowed_hosts'])));
        $config['telephony'] = array_replace($existing['telephony'], ['extensions'=>array_replace($existing['telephony']['extensions'], $extensions)]);
        foreach (['timeout_seconds','cache_seconds'] as $key) $config['aps'][$key] = $existing['aps'][$key];
        foreach (['filename','sender','receiver'] as $key) $config['gdt'][$key] = $existing['gdt'][$key];
    }
    return validateConfig($config);
}

/** @param array<string,mixed> $config */
function validateConfig(array $config): array
{
    $schema = [
        'http' => ['listen', 'public_url', 'allowed_hosts', 'allowed_networks'],
        'ami' => ['host', 'port', 'username', 'secret', 'tls', 'ca_file'],
        'aps' => ['url', 'username', 'password', 'tls_verify', 'ca_file', 'timeout_seconds', 'cache_seconds'],
        'telephony' => ['queues', 'extensions', 'country_code', 'originate_context', 'originate_timeout_ms'],
        'gdt' => ['root', 'filename', 'sender', 'receiver'],
    ];
    $expectedTop = array_merge(array_keys($schema), ['clients']);
    $actualTop = array_keys($config);
    sort($expectedTop);
    sort($actualTop);
    if ($actualTop !== $expectedTop) {
        throw new ConfigurationError('Die Hauptabschnitte entsprechen nicht exakt dem Beispielschema.');
    }
    foreach ($schema as $section => $keys) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            throw new ConfigurationError("Konfigurationsabschnitt fehlt: {$section}");
        }
        $actual = array_keys($config[$section]);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new ConfigurationError("Abschnitt {$section} entspricht nicht exakt dem Beispielschema.");
        }
    }
    if (!is_array($config['clients'])) {
        throw new ConfigurationError('clients muss ein Objekt sein.');
    }
    foreach ($config['clients'] as $id => $client) {
        if (!is_array($client)) {
            throw new ConfigurationError("Client {$id} muss ein Objekt sein.");
        }
        $clientKeys = array_keys($client);
        sort($clientKeys);
        if ($clientKeys !== ['directory', 'extension', 'label']) {
            throw new ConfigurationError("Client {$id} entspricht nicht exakt dem Beispielschema.");
        }
    }
    try { return \Kienzlefon\Config::validate($config); }
    catch (\Throwable $e) { throw new ConfigurationError($e->getMessage()); }
}

function prompt(string $label, string $default = '', bool $secret = false): string
{
    $suffix = $default !== '' && !$secret ? " [{$default}]" : '';
    fwrite(STDERR, $label . $suffix . ': ');
    if ($secret) {
        if (!function_exists('exec')) throw new ConfigurationError('Verdeckte Passworteingabe ist nicht verfügbar.');
        exec('stty -echo 2>/dev/null', $unused, $status);
        if ($status !== 0) throw new ConfigurationError('Verdeckte Passworteingabe benötigt ein Terminal.');
        register_shutdown_function(static function () { exec('stty echo 2>/dev/null'); });
    }
    try { $line = fgets(STDIN); }
    finally { if ($secret) { exec('stty echo 2>/dev/null'); fwrite(STDERR, PHP_EOL); } }
    if ($line === false) {
        throw new ConfigurationError('Eingabe wurde abgebrochen.');
    }
    $value = rtrim($line, "\r\n");
    return $value === '' && !$secret ? $default : $value;
}

function yesNoPrompt(string $label, bool $default): bool
{
    $answer = strtolower(prompt($label . ($default ? ' [J/n]' : ' [j/N]')));
    return $answer === '' ? $default : in_array($answer, ['j', 'ja', 'y', 'yes'], true);
}

/** @param array<string,mixed>|null $existing @return array<string,mixed> */
function interactiveAnswers(?array $existing): array
{
    $aps = $existing['aps'] ?? [];
    $apsUrl = isset($aps['url']) ? parse_url((string)$aps['url']) : [];
    $ami = $existing['ami'] ?? [];
    $answers = [
        'public_url' => prompt('Middleware publicURL', (string)($existing['http']['public_url'] ?? 'http://localhost:8765')),
        'listen_port' => prompt('Middleware Listen-Port', isset($existing['http']['listen']) && preg_match('/:(\d+)$/', (string)$existing['http']['listen'], $listen) ? $listen[1] : '8765'),
        'aps_host' => prompt('APS-Host', (string)($apsUrl['host'] ?? 'localhost')),
        'aps_port' => prompt('APS-Port', (string)($apsUrl['port'] ?? 16567)),
        'aps_username' => prompt('APS-Benutzer', (string)($aps['username'] ?? '')),
        'aps_tls' => true,
        'aps_tls_verify' => !yesNoPrompt('Ausnahme: APS-TLS-Zertifikat NICHT prüfen', isset($aps['tls_verify']) && $aps['tls_verify'] === false),
        'aps_ca_file' => prompt('APS CA-Datei (leer = System-CA)', (string)($aps['ca_file'] ?? '')),
        'ami_host' => prompt('AMI-Host', (string)($ami['host'] ?? '127.0.0.1')),
        'ami_port' => prompt('AMI-Port', (string)($ami['port'] ?? 5038)),
        'ami_username' => prompt('AMI-Benutzer', (string)($ami['username'] ?? 'kienzlefon-middleware')),
        'ami_tls' => yesNoPrompt('AMI über TLS', (bool)($ami['tls'] ?? false)),
        'ami_ca_file' => prompt('AMI CA-Datei (leer = System-CA)', (string)($ami['ca_file'] ?? '')),
        'gdt_root' => prompt('Absoluter GDT-Root', (string)($existing['gdt']['root'] ?? '/srv/kienzlefon-GDT')),
    ];
    if ($existing !== null && yesNoPrompt('Vorhandenes APS-Passwort beibehalten', true)) {
        // Deliberately omit: buildConfig retains it. Choosing "nein" permits explicitly empty.
    } else {
        $answers['aps_password'] = prompt('APS-Passwort (leer ist gültig)', '', true);
    }
    if ($existing !== null && yesNoPrompt('Vorhandenes AMI-Secret beibehalten', true)) {
        // Deliberately omit: buildConfig retains it.
    } else {
        $answers['ami_secret'] = prompt('AMI-Secret', '', true);
    }
    if ($existing !== null && yesNoPrompt('Vorhandene Arbeitsplätze beibehalten', true)) {
        $answers['clients'] = $existing['clients'];
        return $answers;
    }
    $answers['clients'] = [];
    fwrite(STDERR, "Clients eingeben; leere Client-ID beendet die Liste.\n");
    while (true) {
        $id = prompt('Client-ID', '');
        if ($id === '') {
            break;
        }
        $answers['clients'][$id] = [
            'label' => prompt('  Label'),
            'extension' => prompt('  Nebenstelle'),
            'directory' => prompt('  Verzeichnis', $id),
        ];
    }
    return $answers;
}

function main(array $argv): int
{
    try {
        $command = $argv[1] ?? '';
        if ($command === 'validate' && isset($argv[2])) {
            validateConfig(loadJsonFile($argv[2]));
            return 0;
        }
        if ($command === 'paths' && isset($argv[2])) {
            $config = validateConfig(loadJsonFile($argv[2]));
            echo $config['gdt']['root'], "\n";
            foreach ($config['clients'] as $client) {
                echo $client['directory'], "\n";
            }
            return 0;
        }
        if ($command === 'interactive' && isset($argv[2])) {
            $existingPath = $argv[3] ?? '';
            $existing = $existingPath !== '' ? loadJsonFile($existingPath) : null;
            if ($existing !== null) {
                validateConfig($existing);
            }
            $config = buildConfig(interactiveAnswers($existing), $existing);
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (@file_put_contents($argv[2], $json, LOCK_EX) === false) {
                throw new ConfigurationError('Temporäre Konfiguration konnte nicht geschrieben werden.');
            }
            @chmod($argv[2], 0600);
            return 0;
        }
        fwrite(STDERR, "Aufruf: configure.php validate DATEI | paths DATEI | interactive AUSGABE [BESTEHEND]\n");
        return 2;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Konfigurationsfehler: ' . $e->getMessage() . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
