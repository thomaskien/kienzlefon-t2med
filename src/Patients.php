<?php
declare(strict_types=1);
namespace Kienzlefon;

final class Patients
{
    public static function searchKey(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',"a\u{0308}"=>'ae',"o\u{0308}"=>'oe',"u\u{0308}"=>'ue']);
    }
    public static function displayName(array $patient): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $patient['first_name'] . ' ' . $patient['last_name'])), 0, 80);
    }
    public static function publicPatients(array $patients): array
    {
        return array_map(static function ($patient) { unset($patient['_ref'], $patient['phones']); return $patient; }, $patients);
    }
}
