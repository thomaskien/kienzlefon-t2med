#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Kienzlefon\{Config,Ami,Aps,PatientDirectory};
use React\EventLoop\Loop;
use function React\Promise\all;

$options = getopt('', ['config:', 'help']);
if (isset($options['help'])) { echo "php bin/check-connections.php --config=/etc/kienzlefon-t2med/config.toml\nPrüft den lokalen FHIR-Bestand (bei Altbetrieb APS) und lesende AMI-Aktionen. Kein Telefonat und keine GDT-Datei.\n"; exit(0); }
try { $config = Config::load($options['config'] ?? Config::defaultPath()); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
$ami = new Ami($config['ami']);
$done = 0; $failed = false;
$finish = function (bool $ok, string $message) use (&$done, &$failed, $ami): void {
    echo ($ok ? 'OK: ' : 'FEHLER: ') . $message . "\n";
    $failed = $failed || !$ok;
    if (++$done === 2) { $ami->stop(); Loop::stop(); }
};
if ($config['patients']['provider'] === 'aps') (new Aps($config['aps'], $config['telephony']['country_code']))->search('kienzlefon-verbindungstest-ohne-patientenbezug')->then(
    fn() => $finish(true, 'APS-Anmeldung und Antwortformat. Patientensichtbarkeit bitte mit bekannter Rufnummer in der Webansicht prüfen.'),
    fn() => $finish(false, 'APS nicht erreichbar oder Anmeldung, Zertifikat bzw. Antwortformat ungültig.')
);
else {
    $directory = (new PatientDirectory($config['fhir'], $config['telephony']['country_code']))->status();
    $finish($directory['available'], $directory['available'] ? 'Vollständiger lokaler FHIR-Bestand: ' . $directory['patient_count'] . ' Patienten. FHIR-Zugang wird ausschließlich durch den Start aus T2med geprüft.' : 'Noch kein vollständiger FHIR-Bestand. Telefonverzeichnis in T2med aktualisieren. APS bleibt deaktiviert.');
}
$amiDone = false;
$ami->on('connected', function () use ($ami, &$amiDone, $finish) {
    all([$ami->action('CoreShowChannels', [], 'CoreShowChannelsComplete'), $ami->action('QueueStatus', [], 'QueueStatusComplete')])->then(function () use (&$amiDone, $finish, $ami) {
        if ($amiDone) return; $amiDone = true; $finish(true, 'AMI-Anmeldung, CoreShowChannels und QueueStatus.'); $ami->stop();
    }, function () use (&$amiDone, $finish, $ami) {
        if ($amiDone) return; $amiDone = true; $finish(false, 'AMI-Snapshots fehlgeschlagen. reporting-Recht prüfen.'); $ami->stop();
    });
});
Loop::addTimer(15, function () use (&$amiDone, $finish, $ami) { if (!$amiDone) { $amiDone = true; $finish(false, 'Keine vollständige AMI-Verbindung innerhalb von 15 Sekunden.'); $ami->stop(); } });
$ami->start(); Loop::run(); exit($failed ? 1 : 0);
