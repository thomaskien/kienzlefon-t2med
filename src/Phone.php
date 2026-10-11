<?php
declare(strict_types=1);
namespace Kienzlefon;

final class Phone
{
    public static function normalize(string $value): string
    {
        if (strlen($value) > 80 || preg_match('/[^0-9+ ().\/-]/', $value)) throw new \InvalidArgumentException('Ungültige Rufnummer.');
        $number = preg_replace('/[ ().\/-]/', '', $value);
        if (!preg_match('/^\+?[0-9]{3,20}$/D', $number)) throw new \InvalidArgumentException('Ungültige Rufnummer.');
        return str_starts_with($number, '00') ? '+' . substr($number, 2) : $number;
    }
    public static function external(string $value, array $extensions = []): string
    {
        try { $n = self::normalize($value); } catch (\InvalidArgumentException) { return ''; }
        return strlen(ltrim($n, '+')) >= 7 && !isset($extensions[$n]) ? $n : '';
    }
    public static function variants(string $number, string $country = '49'): array
    {
        $n = self::normalize($number);
        $variants = [$n];
        if (str_starts_with($n, '+' . $country)) $variants[] = '0' . substr($n, strlen($country) + 1);
        elseif (str_starts_with($n, '0') && !str_starts_with($n, '00')) $variants[] = '+' . $country . substr($n, 1);
        foreach ($variants as $v) if (str_starts_with($v, '+')) $variants[] = '00' . substr($v, 1);
        return array_values(array_unique($variants));
    }
    public static function key(string $number, string $country = '49'): string
    {
        $v = self::variants($number, $country);
        foreach ($v as $n) if (str_starts_with($n, '+')) return $n;
        return $v[0];
    }
}
