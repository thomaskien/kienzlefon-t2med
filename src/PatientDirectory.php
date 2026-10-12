<?php
declare(strict_types=1);
namespace Kienzlefon;

/** Only reduced telephone-directory data is persisted; never a FHIR response or credential. */
final class PatientDirectory
{
    private ?\PDO $db = null;
    private array $metadata = [];
    public function __construct(private array $config, private string $country = '49')
    {
        try {
            if (is_file($config['database']) && !is_link($config['database'])) {
                $db = new \PDO('sqlite:' . $config['database'], null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                $meta = json_decode($db->query('SELECT value FROM metadata')->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
                if (($meta['source'] ?? '') === $this->source() && ($meta['version'] ?? null) === 1 && ($meta['complete'] ?? false) === true) {
                    $meta = $this->prepareLookup($db, $meta);
                    $this->db = $db; $this->metadata = $meta;
                }
            }
        } catch (\Throwable) { /* A corrupt or foreign snapshot must not act as an empty directory. */ }
    }
    private function source(): string { return hash('sha256', rtrim($this->config['base_url'], '/') . '|' . $this->country . '|' . $this->config['local_area_code']); }
    public function status(): array
    {
        $meta = $this->metadata; unset($meta['source'], $meta['version']);
        return $meta + ['available'=>$this->db !== null,'complete'=>false,'patient_count'=>0,'patients_with_phone'=>0,'phone_count'=>0,'suffix_phone_count'=>0,'unusable_phone_count'=>0,'invalid_patient_numbers'=>0,'imported_at'=>null];
    }
    /** Ambiguous values stay visible in the raw data but cannot become automatic matches. */
    public static function phoneKey(string $value, string $country, string $area): ?string
    {
        $value = trim($value);
        $value = preg_replace('/^tel:/i', '', $value);
        // Written optional national prefix in an international number: +49 (0)30 ...
        $value = preg_replace('/^(\+' . preg_quote($country, '/') . '|00' . preg_quote($country, '/') . ')\s*\(0\)/', '$1', $value);
        try {
            $number = Phone::normalize($value);
            if (str_starts_with($number, '+') || str_starts_with($number, '0')) return Phone::key($number, $country);
            if (str_starts_with($number, $country) && strlen($number) >= strlen($country) + 7 && $number[strlen($country)] !== '0') return Phone::key($number, $country);
            return $area !== '' ? Phone::key($area . $number, $country) : null;
        } catch (\InvalidArgumentException) { return null; }
    }
    private static function localPhoneKey(string $value): ?string
    {
        try { $number = Phone::normalize(preg_replace('/^tel:/i', '', trim($value))); }
        catch (\InvalidArgumentException) { return null; }
        // Match the entire stored local number, never just the last few digits.
        return preg_match('/^[1-9][0-9]{2,19}$/D', $number) ? $number : null;
    }
    private function prepareLookup(\PDO $db, array $meta): array
    {
        // Rebuild from preserved originals, including existing version-1 snapshots.
        // This private index is memory-only; opening a snapshot never changes it.
        $db->exec('PRAGMA temp_store=MEMORY; CREATE TEMP TABLE local_phones (patient_id TEXT NOT NULL, local_key TEXT NOT NULL); CREATE INDEX local_phone_lookup ON local_phones(local_key)');
        $insert = $db->prepare('INSERT INTO local_phones VALUES(?,?)');
        $meta['suffix_phone_count'] = 0; $meta['unusable_phone_count'] = 0;
        $rows = $db->query('SELECT patient_id, original FROM phones WHERE search_key IS NULL');
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            $key = self::localPhoneKey($row['original']);
            if ($key === null) { $meta['unusable_phone_count']++; continue; }
            $insert->execute([$row['patient_id'], $key]); $meta['suffix_phone_count']++;
        }
        $meta['patients_with_phone'] = (int)$db->query('SELECT COUNT(*) FROM (SELECT patient_id FROM phones WHERE search_key IS NOT NULL UNION SELECT patient_id FROM local_phones)')->fetchColumn();
        $db->exec('CREATE TEMP TABLE patient_names (patient_id TEXT PRIMARY KEY, name_key TEXT NOT NULL)');
        $insert = $db->prepare('INSERT INTO patient_names VALUES(?,?)');
        foreach ($db->query('SELECT id,first_name,last_name FROM patients') as $row) $insert->execute([$row['id'], Patients::searchKey($row['first_name'] . ' ' . $row['last_name'])]);
        return $meta;
    }
    public static function searchQuery(string $query): string
    {
        if (!mb_check_encoding($query, 'UTF-8')) throw new \InvalidArgumentException('Ungültiger Suchtext.');
        Config::text($query, 256);
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if ($query === '') throw new \InvalidArgumentException('Bitte Name, Patientennummer oder Telefonnummer eingeben.');
        return $query;
    }
    /** Manual search only: patient numbers must never become caller-ID matches. */
    public function search(string $query): array
    {
        $query = self::searchQuery($query);
        if ($this->db === null) throw new \RuntimeException('Noch kein vollständiger FHIR-Patientenbestand vorhanden. Bitte in T2med aktualisieren.');
        $patients = []; $truncated = false;
        if (preg_match('/^[0-9]{1,12}$/D', $query)) {
            $select = $this->db->prepare('SELECT * FROM patients WHERE patient_number=?');
            $select->execute([(int)$query]);
            foreach ($select as $row) $patients[$row['id']] = $row;
        }
        if (preg_match('/^[+0-9 ().\/-]+$/D', $query)) {
            foreach ($this->lookup($query)['patients'] as $row) $patients[$row['id']] = $row;
        } elseif (preg_match('/\p{L}/u', $query)) {
            $terms = array_values(array_unique(preg_split('/[\s,]+/u', Patients::searchKey($query), -1, PREG_SPLIT_NO_EMPTY)));
            $where = implode(' AND ', array_fill(0, count($terms), 'instr(n.name_key, ?) > 0'));
            $select = $this->db->prepare('SELECT p.* FROM patient_names n JOIN patients p ON p.id=n.patient_id WHERE ' . $where . ' ORDER BY p.last_name,p.first_name,p.id LIMIT 201');
            $select->execute($terms); $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
            $truncated = count($rows) > 200;
            foreach (array_slice($rows, 0, 200) as $row) $patients[$row['id']] = $row;
        }
        $patients = array_values($patients);
        usort($patients, static fn($a,$b) => strcmp($a['last_name'],$b['last_name']) ?: strcmp($a['first_name'],$b['first_name']) ?: strcmp($a['id'],$b['id']));
        return self::result($patients) + ['truncated'=>$truncated];
    }
    public function replace(array $patients): void
    {
        $path = $this->config['database']; $directory = dirname($path); $temp = null; $db = null;
        try {
            if (is_link($directory) || is_link($path)) throw new \RuntimeException();
            if (!is_dir($directory) && !@mkdir($directory, 0700, true)) throw new \RuntimeException();
            $temp = $directory . '/.patients-' . bin2hex(random_bytes(12)) . '.sqlite';
            $file = @fopen($temp, 'x');
            if ($file === false) throw new \RuntimeException();
            fclose($file);
            if (!@chmod($temp, 0600)) throw new \RuntimeException();
            $db = new \PDO('sqlite:' . $temp, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA journal_mode=MEMORY; PRAGMA synchronous=FULL; CREATE TABLE patients (id TEXT PRIMARY KEY, patient_number INTEGER UNIQUE, first_name TEXT NOT NULL, last_name TEXT NOT NULL); CREATE TABLE phones (patient_id TEXT NOT NULL, original TEXT NOT NULL, search_key TEXT); CREATE INDEX phone_lookup ON phones(search_key); CREATE TABLE metadata(value TEXT NOT NULL)');
            $db->beginTransaction();
            $insert = $db->prepare('INSERT INTO patients VALUES(?,?,?,?)');
            $phone = $db->prepare('INSERT INTO phones VALUES(?,?,?)');
            $stats = ['patient_count'=>count($patients),'patients_with_phone'=>0,'phone_count'=>0,'unusable_phone_count'=>0,'invalid_patient_numbers'=>0];
            foreach ($patients as $p) {
                if (!is_array($p) || !is_string($p['id'] ?? null) || !preg_match('/^[A-Za-z0-9.-]{1,64}$/D', $p['id']) || !array_key_exists('patient_number', $p)) throw new \RuntimeException();
                if ($p['patient_number'] !== null) Config::integer($p['patient_number'], 1, 999999999999);
                else $stats['invalid_patient_numbers']++;
                foreach (['first_name','last_name'] as $field) Config::text($p[$field] ?? null, 2048, true);
                if (!is_array($p['phones'] ?? null) || !array_is_list($p['phones'])) throw new \RuntimeException();
                $insert->execute([$p['id'],$p['patient_number'],$p['first_name'],$p['last_name']]);
                $hasPhone = false;
                foreach (array_unique($p['phones'], SORT_REGULAR) as $raw) {
                    Config::text($raw, 2048, true);
                    $key = self::phoneKey($raw, $this->country, $this->config['local_area_code']);
                    $phone->execute([$p['id'],$raw,$key]);
                    $stats['phone_count']++;
                    if ($key === null) $stats['unusable_phone_count']++; else $hasPhone = true;
                }
                if ($hasPhone) $stats['patients_with_phone']++;
            }
            $meta = ['version'=>1,'source'=>$this->source(),'complete'=>true,'imported_at'=>gmdate('c')] + $stats;
            $meta = $this->prepareLookup($db, $meta);
            $db->prepare('INSERT INTO metadata VALUES(?)')->execute([json_encode($meta, JSON_THROW_ON_ERROR)]);
            $db->commit();
            // Keep the already opened, verified connection across the atomic rename.
            // There must be no fallible reopen after replacing the previous snapshot.
            if (!@rename($temp, $path)) throw new \RuntimeException();
            $temp = null;
            $this->db = $db;
            $this->metadata = $meta;
        } catch (\Throwable) {
            throw new \RuntimeException('Patientenbestand konnte nicht sicher gespeichert werden. Bisheriger Bestand bleibt erhalten.');
        } finally { $db = null; if ($temp !== null) @unlink($temp); }
    }
    public function lookup(string $number): array
    {
        if ($this->db === null) throw new \RuntimeException('Noch kein vollständiger FHIR-Patientenbestand vorhanden. Bitte in T2med aktualisieren.');
        $key = self::phoneKey($number, $this->country, $this->config['local_area_code']);
        $localKeys = [];
        if ($key === null) {
            $local = self::localPhoneKey($number);
            if ($local !== null) {
                $localKeys[] = $local;
                // Trunks can send country + short area/local number without +/00.
                // Keep the literal local candidate as well: a stored local number
                // beginning with the country digits must not be reinterpreted.
                if (str_starts_with($local, $this->country) && strlen($local) >= strlen($this->country) + 5 && $local[strlen($this->country)] !== '0') $key = '+' . $local;
            }
        }
        if ($key !== null && str_starts_with($key, '+' . $this->country)) {
            // Only the national part may contain a local number; foreign country
            // codes and complete stored numbers are never matched by suffix.
            $national = substr($key, strlen($this->country) + 1);
            for ($offset = 1; $offset <= strlen($national) - 3; $offset++) {
                $local = self::localPhoneKey(substr($national, $offset));
                if ($local !== null) $localKeys[] = $local;
            }
        }
        $matches = 'SELECT patient_id FROM phones WHERE search_key=?';
        if ($localKeys) $matches .= ' UNION SELECT patient_id FROM local_phones WHERE local_key IN (' . implode(',', array_fill(0, count($localKeys), '?')) . ')';
        $query = $this->db->prepare('SELECT p.* FROM patients p WHERE p.id IN (' . $matches . ') ORDER BY p.last_name,p.first_name,p.id');
        $query->execute([$key, ...$localKeys]); $patients = $query->fetchAll(\PDO::FETCH_ASSOC);
        return self::result($patients);
    }
    private static function result(array $patients): array
    {
        foreach ($patients as &$p) { $p['patient_number'] = $p['patient_number'] === null ? null : (int)$p['patient_number']; $p += ['birth_date'=>'','address'=>'']; }
        unset($p);
        $name = count($patients) === 1 ? Patients::displayName($patients[0]) : '';
        $status = !$patients ? 'not_found' : (count($patients) === 1 && $name !== '' ? 'resolved' : 'ambiguous');
        return ['status'=>$status,'name'=>$status === 'resolved' ? $name : '', 'patients'=>$patients];
    }
}
