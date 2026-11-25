<?php
declare(strict_types=1);

use Kienzlefon\TelephonyContract;
require dirname(__DIR__) . '/vendor/autoload.php';

// Input is the JSON returned by the installed, authenticated App PHP proxy.
$view = json_decode(file_get_contents($argv[1]), true);
function reason(mixed $value, string $fallback): string
{
    return is_string($value) && preg_match('/^[a-z_]{2,48}$/D', $value) ? $value : $fallback;
}
function countValue(mixed $value): bool { return is_int($value) && $value >= 0; }
if (($view['schema_version'] ?? null) !== 1 || ($view['source'] ?? '') !== 'live' || empty($view['extensions'])) {
    fwrite(STDERR, 'App-Proxy liefert keine Live-Nebenstellen (' . reason($view['error']['code'] ?? null, 'invalid_overview') . ").\n");
    exit(1);
}
$errors = [];
$now = microtime(true);
$live = $view['live_counters'] ?? [];
$stamp = TelephonyContract::timestamp($live['as_of'] ?? null);
if (($live['status'] ?? '') !== 'current') {
    $errors[] = 'Live-Zähler (Fon-Control): ' . reason($live['reason_code'] ?? null, 'source_unavailable');
} elseif (!countValue($live['waiting'] ?? null) || !countValue($live['ivr'] ?? null) || !countValue($live['ai'] ?? null)) {
    $errors[] = 'Warteschleife, IVR oder AI ohne gültigen Zählerstand.';
} elseif ($stamp === null || $now - $stamp >= 10 || $stamp - $now > 1 ||
    !countValue($live['age_ms'] ?? null) || $live['age_ms'] >= 10000) {
    $errors[] = 'Live-Zähler (Fon-Control): stale';
}
$today = $view['call_statistics']['today'] ?? [];
$date = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$capture = TelephonyContract::timestamp($today['as_of'] ?? null);
if (!in_array($today['status'] ?? '', ['partial', 'complete'], true) || !countValue($today['incoming_calls'] ?? null)) {
    $errors[] = 'Anrufe heute: ' . reason($today['reason_code'] ?? null, 'statistics_unavailable');
} elseif (($today['date'] ?? '') !== $date || ($today['timezone'] ?? '') !== 'Europe/Berlin') {
    $errors[] = 'Anrufe heute: falscher Berliner Kalendertag.';
} elseif ($capture === null || $now - $capture > 15 || $capture - $now > 1) {
    $errors[] = 'Anrufe heute: keine aktuelle AMI-Erfassung in der Statistikdatenbank.';
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
printf("App-Proxy geprüft. Warteschleife %d, IVR %d, AI %d, Anrufe am %s (Berlin) %d (%s).\n",
    $live['waiting'], $live['ivr'], $live['ai'], $date, $today['incoming_calls'],
    $today['status'] === 'partial' ? 'Teiltag' : 'vollständig');
