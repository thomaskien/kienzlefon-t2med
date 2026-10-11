<?php
declare(strict_types=1);

namespace Kienzlefon\Installer\Samba;

final class SambaConflict extends \RuntimeException {}

function managedShare(string $root, string $serviceUser = 'kienzlefon-t2med'): string
{
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $root) || str_contains($root, '..')) {
        throw new SambaConflict('Unsicherer GDT-Pfad.');
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/', $serviceUser)) {
        throw new SambaConflict('Ungültiger Dienstbenutzer.');
    }
    return "# Managed by kienzlefon-t2med; edit through the installer.\n"
        . "[kienzlefon-GDT]\n"
        . "    path = {$root}\n"
        . "    browseable = yes\n"
        . "    guest ok = yes\n"
        . "    guest only = yes\n"
        . "    read only = no\n"
        . "    force user = {$serviceUser}\n"
        . "    create mask = 0660\n"
        . "    force create mode = 0660\n"
        . "    directory mask = 0770\n"
        . "    force directory mode = 0770\n"
        . "    delete readonly = yes\n";
}

/** @return array<string,list<string>> */
function sections(string $text): array
{
    $result = [];
    $current = '';
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (preg_match('/^\s*\[([^]]+)]\s*$/', $line, $m)) {
            $current = strtolower(trim($m[1]));
            $result[$current] ??= [];
        } elseif ($current !== '') {
            $result[$current][] = $line;
        }
    }
    return $result;
}

function hasInclude(string $main, string $includePath): bool
{
    foreach (sections($main)['global'] ?? [] as $line) {
        if (preg_match('/^\s*include\s*=\s*(.*?)\s*(?:[#;].*)?$/i', $line, $m) && trim($m[1]) === $includePath) {
            return true;
        }
    }
    return false;
}

function appendGlobalSetting(string $main, string $line): string
{
    $eol = str_contains($main, "\r\n") ? "\r\n" : "\n";
    if (!isset(sections($main)['global'])) return "[global]{$eol}    {$line}{$eol}{$eol}" . $main;
    preg_match_all('/^[ \t]*\[([^]]+)](?:[^\r\n]*)/m', $main, $matches, PREG_OFFSET_CAPTURE);
    $insert = strlen($main);
    $inGlobal = false;
    foreach ($matches[1] as $i => [$name, $offset]) {
        if ($inGlobal) { $insert = $matches[0][$i][1]; break; }
        $inGlobal = strtolower(trim($name)) === 'global';
    }
    $before = substr($main, 0, $insert);
    $after = substr($main, $insert);
    return $before . ($before !== '' && !str_ends_with($before, "\n") ? $eol : '') . "    {$line}{$eol}" . $after;

}

/**
 * Pure preflight planner. $effective is canonical testparm output, if Samba exists.
 * @return array{main:string,managed:string,changed:bool,rollback:list<string>}
 */
function plan(string $main, ?string $currentManaged, string $effective, string $root, string $includePath, string $observedMapToGuest, string $observedRole, bool $fresh): array
{
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $includePath) || str_contains($includePath, '..')) {
        throw new SambaConflict('Unsicherer Include-Pfad.');
    }
    $expected = managedShare($root);
    $included = hasInclude($main, $includePath);
    $effectiveHasShare = isset(sections($effective)['kienzlefon-gdt']);

    if ($included && $currentManaged === null) {
        throw new SambaConflict("Include {$includePath} ist eingetragen, aber nicht lesbar.");
    }
    if ($currentManaged !== null && trim($currentManaged) !== trim($expected)) {
        throw new SambaConflict("Bestehendes verwaltetes Include {$includePath} hat abweichenden Inhalt.");
    }
    if ($effectiveHasShare && !($included && $currentManaged !== null && trim($currentManaged) === trim($expected))) {
        throw new SambaConflict('Eine nicht verwaltete Freigabe [kienzlefon-GDT] existiert bereits.');
    }

    $plannedMain = $main;
    if ($fresh) {
        if (!isset(sections($plannedMain)['global'])) {
            $plannedMain = "[global]\n" . ltrim($plannedMain);
        }
        $plannedMain = appendGlobalSetting($plannedMain, 'server role = standalone server');
        $plannedMain = appendGlobalSetting($plannedMain, 'map to guest = Bad User');
    } else {
        if (strcasecmp(trim($observedMapToGuest), 'Bad User') !== 0) {
            throw new SambaConflict("Bestehendes Samba nutzt map to guest={$observedMapToGuest}; erforderlich ist 'Bad User'. Nichts wurde geändert; --skip-samba verwenden und Share separat bereitstellen.");
        }
        if (strcasecmp(trim($observedRole), 'standalone server') !== 0) {
            throw new SambaConflict("Bestehende Samba-Rolle '{$observedRole}' ist nicht 'standalone server'. Nichts wurde geändert; --skip-samba verwenden.");
        }
    }
    if (!$included) {
        $plannedMain = appendGlobalSetting($plannedMain, "include = {$includePath}");
    }

    return [
        'main' => $plannedMain,
        'managed' => $expected,
        'changed' => $plannedMain !== $main || $currentManaged === null,
        'rollback' => ['restore smb.conf byte-for-byte', 'restore or remove managed include', 'reload Samba only if it was active'],
    ];
}

function main(array $argv): int
{
    try {
        $options = getopt('', ['main:', 'managed:', 'effective:', 'root:', 'include:', 'map:', 'role:', 'fresh', 'output:']);
        foreach (['main', 'effective', 'root', 'include', 'output'] as $required) {
            if (!isset($options[$required]) || !is_string($options[$required])) {
                throw new SambaConflict("Option --{$required} fehlt.");
            }
        }
        $main = file_get_contents($options['main']);
        $effective = file_get_contents($options['effective']);
        if ($main === false || $effective === false) {
            throw new SambaConflict('Samba-Eingabedatei ist nicht lesbar.');
        }
        $currentManaged = null;
        if (isset($options['managed']) && is_string($options['managed']) && is_file($options['managed'])) {
            $currentManaged = file_get_contents($options['managed']);
            if ($currentManaged === false) {
                throw new SambaConflict('Samba-Include ist nicht lesbar.');
            }
        }
        $result = plan($main, $currentManaged, $effective, $options['root'], $options['include'], (string)($options['map'] ?? ''), (string)($options['role'] ?? ''), isset($options['fresh']));
        if (!is_dir($options['output']) || !is_writable($options['output'])) {
            throw new SambaConflict('Plan-Ausgabeverzeichnis ist nicht beschreibbar.');
        }
        file_put_contents($options['output'] . '/smb.conf', $result['main']);
        file_put_contents($options['output'] . '/kienzlefon-t2med.conf', $result['managed']);
        file_put_contents($options['output'] . '/plan.json', json_encode(['changed' => $result['changed'], 'rollback' => $result['rollback']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Samba-Konflikt: ' . $e->getMessage() . "\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
