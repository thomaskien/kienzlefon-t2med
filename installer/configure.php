<?php
declare(strict_types=1);

namespace Kienzlefon\Installer;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new \RuntimeException('Composer-Abhängigkeiten fehlen.');
}
require_once $autoload;

use Kienzlefon\Config;

final class ConfigurationError extends \RuntimeException {}

/** @return array<string,mixed> */
function loadConfigFile(string $path): array
{
    try {
        return Config::read($path);
    } catch (\Throwable) {
        throw new ConfigurationError('Konfiguration kann nicht sicher gelesen werden.');
    }
}

/** @deprecated Legacy test helper. */
function loadJsonFile(string $path): array { return loadConfigFile($path); }

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
    $defaults = Config::read(dirname(__DIR__) . '/config.example.json');
    $provider = $answers['patient_provider'] ?? $existing['patients']['provider'] ?? 'fhir';
    if ($provider === 'fhir') {
        $legacy = $existing['aps'] ?? $defaults['aps'];
        $url = parse_url($legacy['url']);
        $answers += ['aps_host'=>$url['host'], 'aps_port'=>$url['port'] ?? 16567, 'aps_username'=>$legacy['username'], 'aps_tls'=>true, 'aps_tls_verify'=>$legacy['tls_verify'], 'aps_ca_file'=>$legacy['ca_file']];
    }
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

    if (!is_array($answers['clients'])) {
        throw new ConfigurationError('Ungültige Arbeitsplatzliste.');
    }
    $clients = [];
    $extensions = [];
    $directories = [];
    foreach ($answers['clients'] as $id => $client) {
        // PHP stores canonical numeric string keys as integers, even after TOML/JSON decoding.
        $id = (string)$id;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id) || !is_array($client)) {
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

    $listenHost = '0.0.0.0';
    if (isset($existing['http']['listen']) && is_string($existing['http']['listen'])
        && preg_match('/^(.*):[0-9]{1,5}$/D', $existing['http']['listen'], $existingListen)) {
        $listenHost = $existingListen[1];
    }
    $config = [
        'http' => [
            'listen' => $listenHost . ':' . $listenPort,
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
            'inactive_extensions' => [],
            'country_code' => '49',
            'originate_context' => 'kienzlefon-click-to-dial',
            'transfer_context' => 'kienzlefon-transfer',
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
        $config['http']['allowed_hosts'] = $existing['http']['allowed_hosts'];
        if ($answers['public_url'] === $existing['http']['public_url']) {
            $config['http']['public_url'] = $existing['http']['public_url'];
        } elseif ($urlHost !== parse_url($existing['http']['public_url'], PHP_URL_HOST)
            && !in_array($urlHost, $config['http']['allowed_hosts'], true)) {
            $config['http']['allowed_hosts'][] = $urlHost;
        }
        $preservedExtensions = $existing['telephony']['extensions'];
        foreach ($existing['clients'] as $client) {
            $oldExtension = $client['extension'];
            if (!isset($extensions[$oldExtension])) unset($preservedExtensions[$oldExtension]);
        }
        foreach ($clients as $id => $client) {
            $old = $existing['clients'][$id] ?? null;
            $extension = $client['extension'];
            if (!isset($preservedExtensions[$extension]) || $old === null
                || $old['extension'] !== $extension || $old['label'] !== $client['label']) {
                $preservedExtensions[$extension] = $client['label'];
            }
        }
        $config['telephony'] = array_replace($existing['telephony'], ['extensions'=>$preservedExtensions]);
        $previousUrl = parse_url($existing['aps']['url']);
        if ($provider === 'fhir' || ($apsHost === $previousUrl['host'] && $apsPort === ($previousUrl['port'] ?? 443))) {
            $config['aps']['url'] = $existing['aps']['url'];
        }
        foreach (['timeout_seconds','cache_seconds'] as $key) $config['aps'][$key] = $existing['aps'][$key];
        foreach (['filename','sender','receiver'] as $key) $config['gdt'][$key] = $existing['gdt'][$key];
    }
    if (isset($answers['inactive_extensions'])) {
        $config['telephony']['inactive_extensions'] = $answers['inactive_extensions'];
        foreach ($answers['inactive_extensions'] as $number) unset($config['telephony']['extensions'][$number]);
    }
    $config['patients'] = ['provider'=>$provider];
    $config['fhir'] = array_replace($defaults['fhir'], $existing['fhir'] ?? []);
    foreach (['base_url','tls_verify','ca_file','local_area_code'] as $field) {
        if (array_key_exists('fhir_' . $field, $answers)) $config['fhir'][$field] = $answers['fhir_' . $field];
    }
    $config['call_statistics'] = array_replace($defaults['call_statistics'], $existing['call_statistics'] ?? []);
    foreach (['telephony_control','app_access'] as $section) $config[$section]=array_replace($defaults[$section],$existing[$section]??[]);

    return validateConfig($config);
}

/** @param array<string,mixed> $config */
function validateConfig(array $config): array
{
    $defaults=Config::read(dirname(__DIR__) . '/config.example.json');
    foreach (['call_statistics','telephony_control','app_access'] as $section) $config[$section] ??= $defaults[$section];
    $config['telephony_control'] += array_intersect_key($defaults['telephony_control'], array_flip(['routing_token_file','legacy_routing']));
    $config['app_access'] += ['workplace_mode' => 'fon'];
    $schema = [
        'http' => ['listen', 'public_url', 'allowed_hosts', 'allowed_networks'],
        'ami' => ['host', 'port', 'username', 'secret', 'tls', 'ca_file'],
        'aps' => ['url', 'username', 'password', 'tls_verify', 'ca_file', 'timeout_seconds', 'cache_seconds'],
        'telephony' => ['queues', 'extensions', 'inactive_extensions', 'country_code', 'originate_context', 'originate_timeout_ms', 'transfer_context'],
        'gdt' => ['root', 'filename', 'sender', 'receiver'],
        'patients' => ['provider'],
        'fhir' => ['base_url','binary','database','tls_verify','ca_file','timeout_seconds','max_pages','max_patients','local_area_code','cache_seconds'],
        'call_statistics' => ['enabled','database_path','timezone','pbx_id'],
        'telephony_control' => ['enabled','base_url','token_file','routing_token_file','legacy_routing','pbx_id','request_database'],
        'app_access' => ['enabled','token_file','allowed_clients','workplace_mode','allowed_networks'],
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
    try { return Config::validate($config); }
    catch (\Throwable $e) { throw new ConfigurationError($e->getMessage()); }
}

/** Check the exact bind address without contacting APS/AMI or stopping other services. */
function checkListen(array $config): void
{
    $listen = $config['http']['listen'];
    $socket = @stream_socket_server('tcp://' . $listen, $errno, $error);
    if ($socket === false) {
        throw new ConfigurationError("HTTP-Listenadresse {$listen} kann nicht verwendet werden: {$error} ({$errno}). "
            . 'Bei belegtem Port einen freien Middleware-Listen-Port und die passende Middleware publicURL wählen '
            . '(vorhandene Konfiguration: installer/install.sh --reconfigure).');
    }
    fclose($socket);
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

function promptFhirBaseUrl(?string $existingUrl): string
{
    $label = 'IP-Adresse des T2med-Servers für FHIR';
    if ($existingUrl !== null) {
        $label .= ' (Enter = ' . parse_url($existingUrl, PHP_URL_HOST) . ' beibehalten)';
    }
    while (true) {
        $ip = trim(prompt($label), " \t");
        if ($ip === '' && $existingUrl !== null) return $existingUrl;
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            return "https://{$host}:16567/aps/fhir/api/r4";
        }
        fwrite(STDERR, "Bitte eine gültige IPv4- oder IPv6-Adresse eingeben.\n");
    }
}

/** Select workplaces by menu index. The phone number remains a string even for 001. */
function selectWorkplaces(?array $existing, array $exportedNumbers): array
{
    $previous = $existing['clients'] ?? [];
    $previousNumbers = array_column($previous, 'extension');
    $active = array_fill_keys($previousNumbers, true);
    $numbers = array_unique(array_merge($previousNumbers, $existing['telephony']['inactive_extensions'] ?? [], $exportedNumbers));
    // An old workplace may use another phone number as its ID or GDT directory.
    $reserved = array_diff(array_merge(array_map('strval', array_keys($previous)), array_column($previous, 'directory')), $previousNumbers);
    $allowAdd = $exportedNumbers === [];
    $requireSelection = getenv('T2MED_REQUIRE_WORKPLACE') === '1' && empty($existing['telephony_control']['enabled']);
    $fonInventory = getenv('T2MED_REQUIRE_WORKPLACE') === 'fon' && $existing === null;
    fwrite(STDERR, "Arbeitsplätze auswählen. Die Nebenstellennummer ist zugleich der Bearbeiter in der Telepraxis-App.\n");
    if ($fonInventory) fwrite(STDERR, "Enter ohne GDT-Auswahl übernimmt die Nebenstellenanzeige aus Fon. Die Rufgruppenfreigabe verwaltet Fon.\n");
    while (true) {
        natsort($numbers);
        $numbers = array_values($numbers);
        foreach ($numbers as $i => $number) {
            fprintf(STDERR, "%3d) %s [%s]\n", $i + 1, $number, isset($active[$number]) ? 'aktiv' : 'inaktiv');
        }
        $addIndex = count($numbers) + 1;
        if ($allowAdd) fprintf(STDERR, "%3d) Weitere Nebenstelle anlegen\n", $addIndex);
        fwrite(STDERR, "  0) Auswahl übernehmen\n");
        $choice = trim(prompt('Menünummern zum Umschalten, z. B. 1 3 (0 oder Enter = übernehmen)'));
        if ($choice === '' || $choice === '0') {
            $displayOnly = array_diff(array_keys($existing['telephony']['extensions'] ?? []), $numbers);
            if ($requireSelection && !$active && !$displayOnly) {
                fwrite(STDERR, "Für die Nebenstellenanzeige der Praxis-App bitte mindestens eine Nebenstelle aktivieren.\n");
                continue;
            }
            break;
        }
        if ($allowAdd && $choice === (string)$addIndex) {
            $number = trim(prompt('Neue Nebenstellennummer'));
            if (!preg_match('/^[0-9]{1,12}$/D', $number) || in_array($number, $numbers, true)) {
                fwrite(STDERR, "Bitte eine neue Nebenstellennummer mit 1 bis 12 Ziffern eingeben.\n");
                continue;
            }
            if (in_array($number, $reserved, true)) {
                fwrite(STDERR, "Diese Nummer ist bereits einem bestehenden Arbeitsplatz zugeordnet.\n");
                continue;
            }
            $numbers[] = $number;
            $active[$number] = true;
            continue;
        }
        $toggle = [];
        foreach (preg_split('/[\s,]+/', $choice) as $index) {
            if (!ctype_digit($index) || !isset($numbers[(int)$index - 1])) {
                fwrite(STDERR, "Bitte angezeigte Menünummern wählen.\n");
                continue 2;
            }
            $number = $numbers[(int)$index - 1];
            if (in_array($number, $reserved, true)) {
                fwrite(STDERR, "Nummer {$number} ist bereits als Kennung oder GDT-Verzeichnis eines anderen Arbeitsplatzes vergeben.\n");
                continue 2;
            }
            $toggle[(int)$index] = $number;
        }
        foreach ($toggle as $number) {
            if (isset($active[$number])) unset($active[$number]);
            else $active[$number] = true;
        }
    }

    $clients = [];
    $inactive = [];
    $mapped = [];
    foreach ($previous as $id => $client) {
        $number = $client['extension'];
        if (!isset($active[$number])) continue;
        if ((string)$id === $number) $client['label'] = $number;
        $clients[$id] = $client;
        $mapped[$number] = true;
    }
    foreach ($numbers as $number) {
        if (!isset($active[$number])) {
            $inactive[] = $number;
        } elseif (!isset($mapped[$number])) {
            $clients[$number] = ['label' => $number, 'extension' => $number, 'directory' => $number];
        }
    }
    foreach ($clients as $id => $client) {
        $query = http_build_query(['arbeitsplatz' => (string)$id, 'benutzer' => $client['extension']], '', '&', PHP_QUERY_RFC3986);
        fwrite(STDERR, "Telepraxis-App für Nebenstelle {$client['extension']}: /telepraxis-app.php?{$query}\n");
    }
    return ['clients' => $clients, 'inactive_extensions' => $fonInventory && !$clients ? [] : $inactive];
}

/** Select a local listener address. Prefer the interface on the FHIR server's subnet. */
function practiceAddress(array $interfaces, string $listenHost, string $fhirHost): ?string
{
    $fhir = @inet_pton(trim($fhirHost, '[]'));
    $candidates = [];
    $sameSubnet = [];
    foreach ($interfaces as $interface) {
        if (empty($interface['up'])) continue;
        foreach ($interface['unicast'] ?? [] as $unicast) {
            $address = $unicast['address'] ?? '';
            if (!\Kienzlefon\HttpApi::allowedAddress($address, ['10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','fc00::/7'])) continue;
            $ip = inet_pton($address);
            $wildcard = strlen($ip) === 4 ? '0.0.0.0' : '::';
            if ($listenHost !== $wildcard && $listenHost !== $address) continue;
            $candidates[$address] = true;
            $mask = @inet_pton($unicast['netmask'] ?? '');
            if ($fhir !== false && $mask !== false && strlen($fhir) === strlen($ip) && strlen($mask) === strlen($ip)
                && ($ip & $mask) === ($fhir & $mask)) $sameSubnet[$address] = true;
        }
    }
    $matches = $sameSubnet ?: $candidates;
    return count($matches) === 1 ? (string)array_key_first($matches) : null;
}

function prepareDirectoryHost(string $path, array $interfaces): void
{
    $config = Config::load($path);
    $http = $config['http'];
    if ($config['patients']['provider'] !== 'fhir') return;
    $urlHost = trim((string)parse_url($http['public_url'], PHP_URL_HOST), '[]');
    // Recognize the installer defaults. Custom hosts, proxies and network rules are explicit decisions.
    if ($urlHost !== 'localhost' || $http['allowed_hosts'] !== ['localhost', '127.0.0.1']) return;
    $listen = parse_url('http://' . $http['listen']);
    $bind = trim($listen['host'], '[]');
    if (in_array($bind, ['127.0.0.1','::1'], true)) return;
    $host = practiceAddress($interfaces, $bind, (string)parse_url($config['fhir']['base_url'], PHP_URL_HOST));
    if ($host === null) {
        fwrite(STDERR, "Praxisadresse nicht eindeutig. Für den Zugriff aus T2med bei --reconfigure die Middleware-URL des Arbeitsplatzes eintragen.\n");
        return;
    }
    $config['http']['allowed_hosts'][] = $host;
    writeTables($path, $config, ['http']);
    $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
    echo 'Telefonverzeichnis für T2med: http://' . $authority . ':' . $listen['port'] . "/telefonverzeichnis-aktualisieren.php\n";
}

/** @param array<string,mixed>|null $existing @return array<string,mixed> */
function interactiveAnswers(?array $existing, ?array $connection = null): array
{
    $importedAmi = $connection['ami'] ?? null;
    $aps = $existing['aps'] ?? [];
    $apsUrl = isset($aps['url']) ? parse_url((string)$aps['url']) : [];
    $ami = $existing['ami'] ?? [];
    $address = $existing === null ? practiceAddress(net_get_interfaces() ?: [], '0.0.0.0', '') : null;
    $defaultUrl = 'http://' . ($address ?? 'localhost') . ':8766';
    $answers = [
        'public_url' => prompt('Middleware-URL für die Arbeitsplätze', (string)($existing['http']['public_url'] ?? $defaultUrl)),
        'listen_port' => prompt('Middleware Listen-Port', isset($existing['http']['listen']) && preg_match('/:(\d+)$/', (string)$existing['http']['listen'], $listen) ? $listen[1] : '8766'),
        'patient_provider' => prompt('Patientenquelle (fhir oder aps)', (string)($existing['patients']['provider'] ?? 'fhir')),
    ];
    if ($answers['patient_provider'] === 'aps') {
        $answers += [
            'aps_host' => prompt('APS-Host', (string)($apsUrl['host'] ?? 'localhost')),
            'aps_port' => prompt('APS-Port', (string)($apsUrl['port'] ?? ($apsUrl ? 443 : 16567))),
            'aps_username' => prompt('APS-Benutzer', (string)($aps['username'] ?? '')),
            'aps_tls' => true,
            'aps_tls_verify' => yesNoPrompt('APS-Serverzertifikat prüfen', $aps['tls_verify'] ?? false),
        ];
        $answers['aps_ca_file'] = $answers['aps_tls_verify']
            ? prompt('APS CA-Datei (leer = System-CA)', (string)($aps['ca_file'] ?? '')) : '';
    } elseif ($answers['patient_provider'] === 'fhir') {
        $fhir = $existing['fhir'] ?? [];
        $answers += [
            'fhir_base_url'=>promptFhirBaseUrl($fhir['base_url'] ?? null),
            'fhir_tls_verify'=>yesNoPrompt('FHIR-Serverzertifikat prüfen', $fhir['tls_verify'] ?? false),
        ];
        $answers['fhir_ca_file'] = $answers['fhir_tls_verify']
            ? prompt('FHIR CA-Datei (leer = System-CA)', (string)($fhir['ca_file'] ?? '')) : '';
        $answers['fhir_local_area_code'] = prompt('Ortsvorwahl für lokale Telefonwerte (leer = keine Ergänzung)', (string)($fhir['local_area_code'] ?? ''));
    } else throw new ConfigurationError('Patientenquelle muss fhir oder aps sein.');
    if ($importedAmi === null) {
        $answers['ami_host'] = prompt('AMI-Host', (string)($ami['host'] ?? '127.0.0.1'));
        $answers['ami_port'] = prompt('AMI-Port', (string)($ami['port'] ?? 5038));
        $answers['ami_username'] = prompt('AMI-Benutzer', (string)($ami['username'] ?? 'kienzlefon-middleware'));
        $answers['ami_tls'] = yesNoPrompt('AMI über TLS', (bool)($ami['tls'] ?? false));
    } else {
        foreach (['host', 'port', 'username', 'tls', 'secret'] as $key) {
            $answers['ami_' . $key] = $importedAmi[$key];
        }
    }
    $answers['ami_ca_file'] = $answers['ami_tls']
        ? prompt('AMI CA-Datei (leer = System-CA)', (string)($ami['ca_file'] ?? '')) : ($ami['ca_file'] ?? '');
    $answers['gdt_root'] = prompt('Absoluter GDT-Root', (string)($existing['gdt']['root'] ?? '/srv/kienzlefon-GDT'));
    if ($answers['patient_provider'] === 'aps' && !($existing !== null && yesNoPrompt('Vorhandenes APS-Passwort beibehalten', true))) {
        $answers['aps_password'] = prompt('APS-Passwort (leer ist gültig)', '', true);
    }
    if ($importedAmi === null && !($existing !== null && yesNoPrompt('Vorhandenes AMI-Secret beibehalten', true))) {
        $answers['ami_secret'] = prompt('AMI-Secret', '', true);
    }
    return $answers + selectWorkplaces($existing, $connection['extensions'] ?? []);
}

function writeToml(string $path, array $config): void
{
    $validated = validateConfig($config);
    try {
        $toml = Config::encodeToml($validated);
    } catch (\Throwable) {
        throw new ConfigurationError('TOML-Konfiguration konnte nicht erzeugt werden.');
    }
    if (@file_put_contents($path, $toml, LOCK_EX) === false) {
        throw new ConfigurationError('Temporäre Konfiguration konnte nicht geschrieben werden.');
    }
    @chmod($path, 0600);
    try {
        $roundTrip = Config::load($path);
    } catch (\Throwable) {
        @unlink($path);
        throw new ConfigurationError('TOML-Konfiguration hat die Kontrollprüfung nicht bestanden.');
    }
    // TOML places an empty top-level array (clients = []) before all tables.
    ksort($roundTrip);
    ksort($validated);
    if ($roundTrip !== $validated) {
        @unlink($path);
        throw new ConfigurationError('TOML-Konfiguration ist nicht verlustfrei serialisierbar.');
    }
}

/** Update managed tables in the private TOML candidate, preserving unrelated settings. */
function writeTables(string $path, array $config, array $sections): void
{
    $updated = file_get_contents($path);
    foreach ($sections as $section) {
        $table = Config::encodeToml([$section => $config[$section]]);
        $pattern = '/^\[' . preg_quote($section, '/') . '\][ \t]*(?:#[^\r\n]*)?\R.*?(?=^\[|\z)/ms';
        $updated = preg_replace_callback($pattern, static fn() => $table . "\n", $updated, 1, $count);
        if (!$count) $updated = rtrim($updated) . "\n\n" . $table;
    }
    if (file_put_contents($path, $updated, LOCK_EX) === false) throw new ConfigurationError('Installationskonfiguration ist nicht schreibbar.');
    try {
        if (Config::load($path) == $config) return;
    } catch (\Throwable) {
        // Alternative TOML table syntax requires serializing the validated model.
    }
    writeToml($path, $config);
}

/** Stage a private token only at first setup. Existing custom paths must already exist. */
function stageServiceKey(string $path, string $managed, string $draft): string
{
    if (file_exists($path) || is_link($path)) {
        try { return \Kienzlefon\TelephonyContract::secret($path); }
        catch (\Throwable) { throw new ConfigurationError('Vorhandener Dienstschlüssel ist ungültig oder benötigt Modus 0600.'); }
    }
    if ($path !== $managed) throw new ConfigurationError('Individuell konfigurierter Dienstschlüssel fehlt.');
    $token = bin2hex(random_bytes(32));
    if (file_put_contents($draft, $token . "\n") === false) throw new ConfigurationError('Dienstschlüssel konnte nicht vorbereitet werden.');
    return $token;
}

function main(array $argv): int
{
    try {
        $command = $argv[1] ?? '';
        if ($command === 'prepare-directory-host' && count($argv) === 3) {
            prepareDirectoryHost($argv[2], net_get_interfaces() ?: []);
            return 0;
        }
        if ($command === 'prepare-counters' && count($argv) === 5) {
            $config = Config::load($argv[2]);
            $work = $argv[3];
            $info = json_decode(file_get_contents($work . '/fon-info.json'), true, flags: JSON_THROW_ON_ERROR);
            $control = $config['telephony_control'];
            $stats = $config['call_statistics'];
            foreach ([$control, $stats] as $section) {
                if ($section['pbx_id'] !== '' && $section['pbx_id'] !== $info['pbx_id']) {
                    throw new ConfigurationError('Abweichende Anlagenkennungen in Fon und Middleware.');
                }
            }
            $url = $info['base_url'];
            if ($control['enabled'] && rtrim($control['base_url'], '/') !== $url) {
                $loopback = preg_replace('~^http://localhost:~', 'http://127.0.0.1:', rtrim($control['base_url'], '/'));
                if ($loopback !== $url) throw new ConfigurationError('Konfigurierter Control-Endpunkt weicht vom lokalen Fon-Dienst ab.');
                $url = $control['base_url'];
            }
            $config['call_statistics']['enabled'] = $config['telephony_control']['enabled'] = true;
            $config['call_statistics']['pbx_id'] = $config['telephony_control']['pbx_id'] = $info['pbx_id'];
            $config['telephony_control']['base_url'] = $url;
            umask(0077);
            $reader = stageServiceKey($control['token_file'], $argv[4], $work . '/control.token');
            if (hash_equals($config['ami']['secret'], $reader)) throw new ConfigurationError('AMI- und Control-Schlüssel müssen getrennt sein.');
            if (file_put_contents($work . '/control.sha256', hash('sha256', $reader)) === false) throw new ConfigurationError('Control-Übergabe ist nicht schreibbar.');
            if (($info['routing_pairing_version'] ?? null) === 1) {
                $managed = dirname($argv[4]) . '/routing.token';
                $path = $control['routing_token_file'] ?: $managed;
                $writer = stageServiceKey($path, $managed, $work . '/routing.token');
                if ($writer === $reader || $writer === $config['ami']['secret']) throw new ConfigurationError('Der Rufgruppenzugang benötigt einen eigenen Schlüssel.');
                if (file_put_contents($work . '/routing.sha256', hash('sha256', $writer)) === false) throw new ConfigurationError('Rufgruppen-Übergabe ist nicht schreibbar.');
                $config['telephony_control']['routing_token_file'] = $path;
                if ($control['enabled'] && $control['routing_token_file'] === '' && is_file($control['token_file']) && is_file($work . '/routing.token')) {
                    touch($work . '/check-legacy-writer');
                }
            } elseif ($control['routing_token_file'] !== '') {
                throw new ConfigurationError('Fon unterstützt die vorhandene Rufgruppenkopplung nicht.');
            }
            if ($control !== $config['telephony_control'] || $stats !== $config['call_statistics']) {
                writeTables($argv[2], $config, ['telephony_control', 'call_statistics']);
            }
            return 0;
        }
        if ($command === 'retain-routing-key' && count($argv) === 4) {
            // Fon plan confirmed the previous control token is a registered external writer.
            $config = Config::load($argv[2]);
            $config['telephony_control']['routing_token_file'] = $config['telephony_control']['token_file'];
            $config['telephony_control']['token_file'] = $argv[3];
            $config['telephony_control']['legacy_routing'] = true;
            writeTables($argv[2], $config, ['telephony_control']);
            return 0;
        }
        if ($command === 'prepare-app' && count($argv) >= 5) {
            $config = Config::load($argv[2]);
            $access = $config['app_access'];
            $listen = parse_url('http://' . $config['http']['listen']);
            $host = match ($listen['host']) {
                '0.0.0.0' => '127.0.0.1', '[::]' => '[::1]', default => $listen['host']
            };
            $addresses = $argv[4] === '' ? [] : [$argv[4]];
            $localIp = trim($host, '[]');
            if (($argv[5] ?? 'yes') === 'yes' && filter_var($localIp, FILTER_VALIDATE_IP)
                && !\Kienzlefon\HttpApi::allowedAddress($localIp, $access['allowed_networks'])) {
                $addresses[] = $localIp;
            }
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP)) throw new ConfigurationError('Ungültige App-Quell-IP.');
                $network = $address . (str_contains($address, ':') ? '/128' : '/32');
                if (!in_array($network, $access['allowed_networks'], true)) $access['allowed_networks'][] = $network;
            }
            $access['enabled'] = true;
            if ($access !== $config['app_access']) {
                $config['app_access'] = $access;
                writeTables($argv[2], $config, ['app_access']);
            }
            $clients = [];
            foreach ($access['allowed_clients'] as $id) {
                if (!isset($config['clients'][$id])) continue; // Only GDT workplaces have legacy UI labels and extensions.
                $c = $config['clients'][$id];
                $clients[] = ['id' => $id, 'label' => $c['label'], 'extension' => $c['extension']];
            }
            $app = ['enabled' => true, 'middleware_url' => 'http://' . $host . ':' . $listen['port'],
                'token_file' => '/etc/kienzlefon-app/t2med-token', 'clients' => $clients];
            $policy = empty($argv[6]) ? [] : json_decode(file_get_contents($argv[6]), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($policy)) throw new ConfigurationError('Ungültige App-Arbeitsplatzfreigabe.');
            if (array_key_exists('clients', $policy) && (!is_array($policy['clients']) || !array_is_list($policy['clients']))) {
                throw new ConfigurationError('Ungültige App-Arbeitsplatzliste.');
            }
            if (!empty($policy['clients']) || array_key_exists('workplace_mode', $policy)) {
                $app['clients'] = [];
                foreach ($policy['clients'] ?? [] as $client) {
                    if (!\Kienzlefon\TelephonyContract::valid('AppClient', $client, true)) {
                        throw new ConfigurationError('Ungültiger Eintrag in der App-Arbeitsplatzliste.');
                    }
                    $app['clients'][] = \Kienzlefon\TelephonyContract::project('AppClient', $client);
                }
            }
            if (array_key_exists('workplace_mode', $policy)) {
                if (!is_string($policy['workplace_mode']) || $policy['workplace_mode'] === '') {
                    throw new ConfigurationError('Ungültiger Arbeitsplatzmodus in der App.');
                }
                $app['workplace_mode'] = $policy['workplace_mode'];
            }
            $pbx = $config['telephony_control']['pbx_id'];
            $appPbx = $policy['pbx_id'] ?? '';
            if ($appPbx !== '' && !\Kienzlefon\TelephonyContract::valid('PbxId', $appPbx)) {
                throw new ConfigurationError('Ungültige Anlagenkennung in der App.');
            }
            if ($appPbx !== '' && $pbx !== '' && $appPbx !== $pbx) {
                throw new ConfigurationError('Abweichende Anlagenkennungen in App und Middleware.');
            }
            if ($pbx !== '' || $appPbx !== '') $app['pbx_id'] = $pbx !== '' ? $pbx : $appPbx;
            if (file_put_contents($argv[3], "<?php\n// Kienzlefon Installer: t2med\nreturn " . var_export($app, true) . ";\n") === false) {
                throw new ConfigurationError('App-Konfiguration konnte nicht vorbereitet werden.');
            }
            echo $access['token_file'];
            return 0;
        }
        if ($command === 'validate' && isset($argv[2])) {
            Config::load($argv[2]);
            return 0;
        }
        if ($command === 'migrate' && isset($argv[2], $argv[3])) {
            writeToml($argv[3], Config::load($argv[2]));
            return 0;
        }
        if ($command === 'import-ami' && count($argv) === 5) {
            $config = Config::load($argv[2]);
            $connection = loadConfigFile($argv[3]);
            $config['ami'] = array_replace($config['ami'], $connection['ami']);
            writeToml($argv[4], $config);
            return 0;
        }
        if ($command === 'check-listen' && isset($argv[2])) {
            checkListen(Config::load($argv[2]));
            return 0;
        }
        if ($command === 'paths' && isset($argv[2])) {
            $config = Config::load($argv[2]);
            echo $config['gdt']['root'], "\n";
            foreach ($config['clients'] as $client) {
                echo $client['directory'], "\n";
            }
            return 0;
        }
        if ($command === 'control-directory' && isset($argv[2])) {
            $config=Config::load($argv[2]);
            if ($config['telephony_control']['enabled']) echo dirname($config['telephony_control']['request_database']);
            return 0;
        }
        if ($command === 'statistics-directory' && isset($argv[2])) {
            $config = Config::load($argv[2]);
            if ($config['call_statistics']['enabled']) echo dirname($config['call_statistics']['database_path']);
            return 0;
        }
        if ($command === 'interactive' && isset($argv[2])) {
            $existingPath = $argv[3] ?? '';
            $existing = $existingPath !== '' ? Config::load($existingPath) : null;
            if ($existing !== null) {
                validateConfig($existing);
            }
            $importPath = $argv[4] ?? '';
            $connection = $importPath !== '' ? loadConfigFile($importPath) : null;
            $config = buildConfig(interactiveAnswers($existing, $connection), $existing);
            writeToml($argv[2], $config);
            return 0;
        }
        fwrite(STDERR, "Aufruf: configure.php validate DATEI | migrate JSON TOML | import-ami BESTEHEND AMI-JSON AUSGABE | check-listen DATEI | paths DATEI | control-directory DATEI | statistics-directory DATEI | interactive AUSGABE [BESTEHEND [AMI-JSON]]\n");
        return 2;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Konfigurationsfehler: ' . $e->getMessage() . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
