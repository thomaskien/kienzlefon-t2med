<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/installer/configure.php';
require dirname(__DIR__, 2) . '/installer/samba.php';

use Kienzlefon\Installer\ConfigurationError;
use Kienzlefon\Config;
use Kienzlefon\Installer\Samba\SambaConflict;
use function Kienzlefon\Installer\buildConfig;
use function Kienzlefon\Installer\validateConfig;
use function Kienzlefon\Installer\writeToml;
use function Kienzlefon\Installer\Samba\managedShare;
use function Kienzlefon\Installer\Samba\plan;

$tests = 0;
$failures = 0;

function check(bool $condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FEHLER: {$message}\n");
    }
}

function throws(callable $fn, string $class, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (keine Exception)');
    } catch (Throwable $e) {
        check($e instanceof $class, $message . ' (falsche Exception: ' . get_class($e) . ')');
    }
}

function answers(): array
{
    return [
        'public_url' => 'http://middleware.example:8765',
        'patient_provider'=>'aps',
        'listen_port' => 8765,
        'aps_host' => 'aps.example', 'aps_port' => '16567', 'aps_username' => 'test-user', 'aps_password' => '',
        'aps_tls' => true, 'aps_tls_verify' => false, 'aps_ca_file' => '',
        'ami_host' => 'pbx.example', 'ami_port' => 5038, 'ami_username' => 'middleware', 'ami_secret' => 'initial',
        'ami_tls' => false, 'ami_ca_file' => '', 'gdt_root' => '/srv/kienzlefon-GDT',
        'clients' => ['empfang-1' => ['label' => 'Empfang 1', 'extension' => '201', 'directory' => 'empfang-1']],
    ];
}

$include = '/etc/samba/kienzlefon-t2med.conf';
$root = '/srv/kienzlefon-GDT';
$existingMain = "# keep this comment\n[global]\n    map to guest = Bad User\n    server role = standalone server\n\n[documents]\n    path = /srv/documents\n";
$compatible = plan($existingMain, null, $existingMain, $root, $include, 'Bad User', 'standalone server', false);
check(str_contains($compatible['main'], '# keep this comment'), 'bestehender Kommentar bleibt erhalten');
check(str_contains($compatible['main'], '[documents]'), 'bestehende Freigabe bleibt erhalten');
check(str_contains($compatible['main'], "include = {$include}"), 'Include wird additiv gesetzt');
check($compatible['managed'] === managedShare($root), 'verwaltete Freigabe ist deterministisch');

$faxMain = "[global]\n    server role = auto\n    map to guest = Bad User\n"
    . "[fax-eingang]\n    path = /srv/kienzlefax/fax-eingang\n[pdf-zu-fax]\n    path = /srv/kienzlefax/pdf-zu-fax\n";
$auto = plan($faxMain, null, $faxMain, $root, $include, 'Bad User', 'auto', false, 'ROLE_STANDALONE');
check($auto['main'] === str_replace('[fax-eingang]', "    include = {$include}\n[fax-eingang]", $faxMain),
    'Auto-Standalone ergänzt genau das Include und erhält Rolle und Fax-Freigaben bytegleich');
check($auto['managed'] === managedShare($root), 'Bestätigtes Auto-Standalone richtet die GDT-Freigabe ein');
foreach (['', 'ROLE_DOMAIN_MEMBER', 'ROLE_ACTIVE_DIRECTORY_DC', 'widersprüchliche Rollenmeldungen'] as $report) {
    throws(fn() => plan($faxMain, null, $faxMain, $root, $include, 'Bad User', 'auto', false, $report),
        SambaConflict::class, "Auto ohne eindeutige Standalone-Bestätigung wird abgelehnt ({$report})");
}
throws(fn() => plan($faxMain, null, $faxMain, $root, $include, 'Never', 'auto', false, 'ROLE_STANDALONE'),
    SambaConflict::class, 'Auch Auto-Standalone benötigt map to guest = Bad User');
throws(fn() => plan($faxMain, null, $faxMain . "[kienzlefon-GDT]\n", $root, $include, 'Bad User', 'auto', false, 'ROLE_STANDALONE'),
    SambaConflict::class, 'Auch Auto-Standalone lehnt eine fremde GDT-Freigabe ab');
throws(fn() => plan($auto['main'], "[fremd]\n", $faxMain, $root, $include, 'Bad User', 'auto', false, 'ROLE_STANDALONE'),
    SambaConflict::class, 'Auch Auto-Standalone lehnt ein kollidierendes Include ab');
throws(fn() => plan($existingMain, null, $existingMain, $root, $include, 'Bad User', 'member server', false, 'ROLE_STANDALONE'),
    SambaConflict::class, 'Eine explizite Domänenrolle wird durch die Rollenmeldung nicht freigegeben');

$effectiveManaged = $compatible['main'] . "\n" . $compatible['managed'];
$again = plan($compatible['main'], $compatible['managed'], $effectiveManaged, $root, $include, 'Bad User', 'standalone server', false);
check($again['main'] === $compatible['main'] && $again['changed'] === false, 'Samba-Plan ist idempotent');

throws(
    fn() => plan("[global]\n include = {$include}\n", "[other]\n path=/tmp\n", '', $root, $include, 'Bad User', 'standalone server', false),
    SambaConflict::class,
    'abweichendes verwaltetes Include wird als Konflikt erkannt'
);
throws(
    fn() => plan($existingMain, null, "[kienzlefon-GDT]\n path=/foreign\n", $root, $include, 'Bad User', 'standalone server', false),
    SambaConflict::class,
    'nicht verwaltete Share-Kollision wird erkannt'
);
throws(
    fn() => plan($existingMain, null, $existingMain, $root, $include, 'Never', 'standalone server', false),
    SambaConflict::class,
    'inkompatibles map to guest wird nicht verändert'
);
throws(
    fn() => plan($existingMain, null, $existingMain, $root, $include, 'Bad User', 'active directory domain controller', false),
    SambaConflict::class,
    'inkompatible Serverrolle wird nicht verändert'
);
$fresh = plan("[global]\n", null, '', $root, $include, '', '', true);
check(str_contains($fresh['main'], 'map to guest = Bad User') && str_contains($fresh['main'], 'server role = standalone server'), 'frische Installation plant standalone guest explizit');
check($fresh['rollback'] === ['restore smb.conf byte-for-byte', 'restore or remove managed include', 'reload Samba only if it was active'], 'Rollback-Plan deckt alle Samba-Änderungen ab');

$emptyPassword = buildConfig(answers());
check($emptyPassword['aps']['password'] === '', 'leeres APS-Passwort ist gültig');
$withoutSecrets = answers();
unset($withoutSecrets['aps_password'], $withoutSecrets['ami_secret']);
$retained = buildConfig($withoutSecrets, $emptyPassword + ['ami' => ['secret' => 'wrong']]);
check($retained['ami']['secret'] === 'initial', 'vorhandenes AMI-Secret bleibt erhalten');
$existingWithSecret = $emptyPassword;
$existingWithSecret['aps']['password'] = 'retained-password';
$existingWithSecret['ami']['secret'] = 'retained-secret';
$retained = buildConfig($withoutSecrets, $existingWithSecret);
check($retained['aps']['password'] === 'retained-password' && $retained['ami']['secret'] === 'retained-secret', 'beide Secrets werden bei ausgelassenen Feldern beibehalten');
$explicitEmpty = $withoutSecrets;
$explicitEmpty['aps_password'] = '';
$explicitEmpty['ami_secret'] = '';
$cleared = buildConfig($explicitEmpty, $existingWithSecret);
check($cleared['aps']['password'] === '' && $cleared['ami']['secret'] === '', 'Secrets können explizit leer gesetzt werden');

$example = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config.example.json'), true, 512, JSON_THROW_ON_ERROR);
$fhirAnswers = answers();
foreach (array_keys($fhirAnswers) as $key) if (str_starts_with($key, 'aps_')) unset($fhirAnswers[$key]);
$fhirAnswers += ['fhir_base_url'=>'https://fhir.example:16567/aps/fhir/api/r4','fhir_tls_verify'=>true,'fhir_ca_file'=>'','fhir_local_area_code'=>'030'];
$fhirAnswers['patient_provider'] = 'fhir';
$fhirConfig = buildConfig($fhirAnswers, $existingWithSecret);
check($fhirConfig['patients']['provider'] === 'fhir', 'FHIR wird ohne APS-Eingaben aktiviert');
check($fhirConfig['aps']['password'] === 'retained-password', 'deaktivierter APS-Zugang bleibt erhalten');
check($fhirConfig['fhir']['base_url'] === $fhirAnswers['fhir_base_url'] && $fhirConfig['fhir']['local_area_code'] === '030', 'FHIR-Ziel und ausdrückliche Ortsvorwahl werden übernommen');
$validated = validateConfig($example);
check(array_keys($validated) === array_keys($example), 'erzeugtes Configschema hat exakt die Beispiel-Hauptabschnitte');
check(array_keys($validated['aps']) === array_keys($example['aps']), 'APS-Schema entspricht exakt dem Beispiel');
check(array_keys($validated['ami']) === array_keys($example['ami']), 'AMI-Schema entspricht exakt dem Beispiel');

$tomlExample = Config::load(dirname(__DIR__, 2) . '/config.example.toml');
check($tomlExample === $validated, 'TOML- und Legacy-JSON-Beispiel sind wertgleich');

$withStatistics = $validated;
$withStatistics['call_statistics']['enabled'] = true;
$withStatistics['call_statistics']['pbx_id'] = 'practice-test';
$withStatistics['call_statistics']['database_path'] = '/var/lib/kienzlefon-t2med/team-statistics.sqlite';
check(buildConfig(answers(), $withStatistics)['call_statistics'] === $withStatistics['call_statistics'], 'Neukonfiguration erhält Statistik und Dateipfad');
$withControl=$withStatistics;
$withControl['telephony_control']['enabled']=true;
$withControl['telephony_control']['pbx_id']='practice-test';
$withControl['telephony_control']['base_url']='http://127.0.0.1:8767';
$withControl['app_access']['enabled']=true;
$withControl['app_access']['allowed_clients']=['empfang-1'];
check(buildConfig(answers(),$withControl)['telephony_control']===$withControl['telephony_control'], 'Reconfiguration retains enabled control endpoint and journal');
check(buildConfig(answers(),$withControl)['app_access']===$withControl['app_access'], 'Reconfiguration retains restricted app clients and networks');
$legacyControl=$withStatistics;unset($legacyControl['telephony_control'],$legacyControl['app_access']);
check(validateConfig($legacyControl)['telephony_control']['enabled']===false, 'Old config never enables control automatically');
$legacyStatistics = $validated;
unset($legacyStatistics['call_statistics']);
check(validateConfig($legacyStatistics)['call_statistics']['enabled'] === false, 'Installer akzeptiert alte Konfiguration ohne Statistik');
check(buildConfig(answers(), $legacyStatistics)['call_statistics']['enabled'] === false, 'Upgrade aktiviert Erfassung nicht ohne Ereignisquelle');

$special = $validated;
$special['ami']['secret'] = 'Raute #, "Anführung", Backslash \\ und Umlaut ä';
$special['aps']['password'] = '';
$special['telephony']['queues'] = [];
$special['telephony']['extensions'] = ['007' => 'Empfang # "Nord"'];
$special['clients'] = ['client_7' => ['label' => 'Empfang # "Nord"', 'extension' => '007', 'directory' => 'client_7']];
$tempDirectory = sys_get_temp_dir() . '/kienzlefon-toml-' . bin2hex(random_bytes(8));
mkdir($tempDirectory, 0700);
$specialPath = $tempDirectory . '/config.toml';
writeToml($specialPath, $special);
check(Config::load($specialPath) === Config::validate($special), 'JSON -> TOML -> Config erhält Sonderzeichen, leere Arrays und Schlüssel verlustfrei');

// Both the reported browser authority and the FHIR destination come from independent interfaces.
$directoryConfig = Config::load(dirname(__DIR__, 2) . '/config.example.toml');
$directoryConfig['fhir']['base_url'] = 'https://10.0.83.120:16567/aps/fhir/api/r4';
$directoryPath = $tempDirectory . '/directory.toml';
writeToml($directoryPath, $directoryConfig);
$interfaces = [['up'=>true, 'unicast'=>[
    ['address'=>'10.0.83.85','netmask'=>'255.255.255.0'],
    ['address'=>'172.17.0.1','netmask'=>'255.255.0.0'],
]]];
$uri = 'http://10.0.83.85:8766/telefonverzeichnis-aktualisieren.php';
$request = new React\Http\Message\ServerRequest('GET', $uri, [], '', '1.1', ['REMOTE_ADDR'=>'10.0.83.27']);
$directoryApi = fn() => new Kienzlefon\HttpApi(new Kienzlefon\Runtime(Config::load($directoryPath), true));
check($directoryApi()($request)->getStatusCode() === 403, 'Alte localhost-Konfiguration reproduziert HTTP 403 beim FHIR-Start');
Kienzlefon\Installer\prepareDirectoryHost($directoryPath, $interfaces);
$expected = $directoryConfig;
$expected['http']['allowed_hosts'][] = '10.0.83.85';
check(Config::load($directoryPath) == $expected, 'Freigabe ergänzt nur die lokale Adresse im FHIR-Netz');
check($directoryApi()($request)->getStatusCode() === 200, 'FHIR-Startseite vom Praxisarbeitsplatz abrufbar');
check($directoryApi()($request->withHeader('Origin', 'https://other.example'))->getStatusCode() === 403, 'Fremder Browser-Ursprung bleibt gesperrt');
$outside = new React\Http\Message\ServerRequest('GET', $uri, [], '', '1.1', ['REMOTE_ADDR'=>'192.0.2.27']);
check($directoryApi()($outside)->getStatusCode() === 403, 'Fremdes Quellnetz bleibt gesperrt');
$first = file_get_contents($directoryPath);
Kienzlefon\Installer\prepareDirectoryHost($directoryPath, $interfaces);
check($first === file_get_contents($directoryPath), 'Zweiter Lauf behält Hostregeln bytegleich');
$directoryConfig['http']['allowed_hosts'] = ['localhost'];
writeToml($directoryPath, $directoryConfig);
Kienzlefon\Installer\prepareDirectoryHost($directoryPath, $interfaces);
check(Config::load($directoryPath) == $directoryConfig, 'Individuelle Hostbeschränkung bleibt erhalten');
unlink($directoryPath);

foreach ([['201'], ['0'], ['0', '1'], ['201', '001', '1', '0', 'pc-201']] as $ids) {
    $numeric = answers();
    $numeric['clients'] = [];
    foreach ($ids as $id) {
        $numeric['clients'][$id] = ['label' => 'Arbeitsplatz ' . $id, 'extension' => '201', 'directory' => $id];
    }
    try {
        $built = buildConfig($numeric);
        check($built['clients'] === $numeric['clients'], 'Installer erhält Client-IDs: ' . implode(', ', $ids));
        writeToml($specialPath, $built);
        $loaded = Config::load($specialPath);
        check($loaded === $built, 'Numerische Client-IDs überstehen TOML-Roundtrip verlustfrei');
        check(buildConfig($numeric, $loaded) === $built, 'Neukonfiguration erhält numerische Client-IDs');
    } catch (Throwable $e) {
        check(false, 'Numerische Client-IDs werden akzeptiert: ' . implode(', ', $ids) . ' (' . $e->getMessage() . ')');
    }
}
foreach (['-1', '+201', '2.01', '201/../../x', "201\n", str_repeat('2', 65)] as $id) {
    $invalidId = answers();
    $invalidId['clients'] = [$id => ['label' => 'Test', 'extension' => '201', 'directory' => 'test']];
    throws(fn() => buildConfig($invalidId), ConfigurationError::class, 'Ungültige Client-ID bleibt gesperrt: ' . json_encode($id));
}

$badTomlPath = $tempDirectory . '/invalid.toml';
$secretMarker = 'NICHT_AUSGEBEN_42';
file_put_contents($badTomlPath, "[ami]\nsecret = \"{$secretMarker}\"\nsecret = \"doppelt\"\n");
$badMessage = '';
try { Config::read($badTomlPath); }
catch (Throwable $e) { $badMessage = $e->getMessage(); }
check($badMessage === 'Ungültige TOML-Konfiguration.' && !str_contains($badMessage, $secretMarker), 'Parserfehler und doppelte Keys geben weder Quelle noch Secret aus');

ob_start();
Config::validate($validated);
$validationOutput = ob_get_clean();
check($validationOutput === '', 'Validierung schreibt keinen Konfigurationsinhalt nach stdout');

$bad = answers();
$bad['clients'] = ['../escape' => ['label' => 'x', 'extension' => '201', 'directory' => '../x']];
throws(fn() => buildConfig($bad), ConfigurationError::class, 'Pfad-/Clientinjektion wird abgewiesen');
$bad = answers();
$bad['public_url'] = 'http://user:pass@example.test/path?x=1';
throws(fn() => buildConfig($bad), ConfigurationError::class, 'unsichere publicURL wird abgewiesen');
$bad = answers();
$bad['gdt_root'] = '/srv/x;touch_bad';
throws(fn() => buildConfig($bad), ConfigurationError::class, 'Shellzeichen im GDT-Pfad werden abgewiesen');
$bad = answers();
$bad['gdt_root'] = '/';
throws(fn() => buildConfig($bad), ConfigurationError::class, 'Dateisystemwurzel wird als GDT-Root abgewiesen');
$bad = answers();
$bad['clients']['empfang-1']['extension'] = "201\nInjected";
throws(fn() => buildConfig($bad), ConfigurationError::class, 'Steuerzeichen in Nebenstelle werden abgewiesen');

$crlf = "# preserve\r\n[global]\r\n    map to guest = Bad User  \r\n\r\n[files]\r\n    path = /srv/files  \r\n";
$planned = plan($crlf, null, $crlf, $root, $include, 'Bad User', 'standalone server', false);
check(str_replace("    include = {$include}\r\n", '', $planned['main']) === $crlf, 'Samba-Hauptdatei bleibt abseits der Include-Zeile bytegenau erhalten');
$custom = $existingWithSecret;
$custom['telephony']['queues'] = ['custom-queue'];
$custom['telephony']['originate_context'] = 'custom-outgoing';
$custom['http']['allowed_networks'] = ['10.20.0.0/16'];
$custom['http']['listen'] = '127.0.0.1:8765';
$updated = buildConfig($withoutSecrets, $custom);
check($updated['telephony']['queues'] === ['custom-queue'] && $updated['telephony']['originate_context'] === 'custom-outgoing' && $updated['http']['allowed_networks'] === ['10.20.0.0/16'] && $updated['http']['listen'] === '127.0.0.1:8765', 'Neukonfiguration erhält bestehende Routing-, Binde- und Netzwerkeinstellungen');
$changedClients = $withoutSecrets;
$changedClients['clients'] = ['labor' => ['label' => 'Labor', 'extension' => '303', 'directory' => 'labor-gdt']];
$withChangedClients = buildConfig($changedClients, $custom);
check(!isset($withChangedClients['telephony']['extensions']['201']) && $withChangedClients['telephony']['extensions']['303'] === 'Labor', 'Arbeitsplätze können mit Nebenstelle und Verzeichnis ersetzt werden');
$bad = answers(); $bad['aps_tls'] = false;
throws(fn() => buildConfig($bad), ConfigurationError::class, 'Zertifikatsausnahme darf HTTPS nicht abschalten');
unlink($specialPath);
unlink($badTomlPath);
rmdir($tempDirectory);
printf("%d Prüfungen, %d Fehler\n", $tests, $failures);
exit($failures === 0 ? 0 : 1);
