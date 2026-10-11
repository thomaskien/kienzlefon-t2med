<?php
declare(strict_types=1);
namespace Kienzlefon;

final class Gdt
{
    public function __construct(private array $config, private array $clients) {}
    public static function record(int $number, string $sender = 'AUFRUF', string $receiver = 'T2MED_PX'): string
    {
        if ($number < 1 || $number > 999999999999) throw new \InvalidArgumentException('Ungültige Patientennummer.');
        foreach ([$sender,$receiver] as $v) if (!preg_match('/^[A-Za-z0-9_-]{1,8}$/D', $v)) throw new \InvalidArgumentException('Ungültige GDT-Kennung.');
        $fields = [['8000','6301'],['8100','00000'],['8315',$receiver],['8316',$sender],['9206','2'],['9218','02.10'],['3000',(string)$number]];
        // All payload values are ASCII, which is byte-identical in CP437.
        $encode = static function (array $rows): string {
            $out = '';
            foreach ($rows as [$key,$value]) $out .= sprintf('%03d', strlen($value) + 9) . $key . $value . "\r\n";
            return $out;
        };
        $fields[1][1] = sprintf('%05d', strlen($encode($fields)));
        return $encode($fields);
    }
    public function write(string $clientId, int $number): array
    {
        $client = $this->clients[$clientId] ?? throw new \InvalidArgumentException('Unbekannter Arbeitsplatz.');
        $root = realpath($this->config['root']);
        $folder = $this->config['root'] . '/' . $client['directory'];
        if (!$root || is_link($folder) || realpath($folder) !== $root . '/' . $client['directory'] || !is_dir($folder)) throw new \RuntimeException('GDT-Verzeichnis des Arbeitsplatzes fehlt oder ist ungültig.');
        $target = $folder . '/' . $this->config['filename'];
        $payload = self::record($number, $this->config['sender'], $this->config['receiver']);
        $temp = tempnam($folder, '.kienzlefon-');
        if ($temp === false || dirname($temp) !== realpath($folder)) { if ($temp) @unlink($temp); throw new \RuntimeException('GDT-Verzeichnis nicht beschreibbar.'); }
        try {
            $file = fopen($temp, 'wb');
            if (!$file) throw new \RuntimeException('GDT-Datei konnte nicht erstellt werden.');
            try {
                if (fwrite($file, $payload) !== strlen($payload) || !fflush($file) || !fsync($file)) throw new \RuntimeException('GDT-Datei konnte nicht vollständig geschrieben werden.');
            } finally { fclose($file); }
            chmod($temp, 0660);
            if (!@link($temp, $target)) {
                if (file_exists($target) || is_link($target)) throw new \RuntimeException('T2med hat den vorherigen Aufruf noch nicht abgeholt.');
                throw new \RuntimeException('GDT-Datei konnte nicht veröffentlicht werden.');
            }
        } finally { @unlink($temp); }
        return ['status'=>'sent', 'message'=>'Aufruf an T2med übergeben.'];
    }
}
