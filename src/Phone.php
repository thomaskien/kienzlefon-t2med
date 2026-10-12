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
        // A national number can have a two-digit area code and three local digits,
        // e.g. 030111. Known extensions still always remain internal.
        $minimum = str_starts_with($n, '0') ? 6 : 7;
        return strlen(ltrim($n, '+')) >= $minimum && !isset($extensions[$n]) ? $n : '';
    }
    public static function variants(string $number, string $country = '49'): array
    {
        $n = self::normalize($number);
        // Some trunks send the configured country code without +/00. Do not
        // reinterpret short local numbers or extensions as international ones.
        if (str_starts_with($n, $country) && strlen($n) >= strlen($country) + 7 && $n[strlen($country)] !== '0') $n = '+' . $n;
        $variants = [$n];
        if (str_starts_with($n, '+' . $country)) {
            $international = substr($n, 1);
            return ['0' . substr($international, strlen($country)), '+' . $international, '00' . $international, $international];
        }
        if (str_starts_with($n, '0') && !str_starts_with($n, '00')) {
            $international = $country . substr($n, 1);
            return [$n, '+' . $international, '00' . $international, $international];
        }
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
