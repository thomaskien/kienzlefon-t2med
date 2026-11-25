<?php
declare(strict_types=1);
namespace Kienzlefon;

/** Technical ingress/route counters. AMI snapshots and HTTP reads never count calls. */
final class CallStatistics
{
    public const ROUTES = [
        'ivr'=>'Sprachmenü', 'ai'=>'AI', 'queue'=>'Warteschlange',
        'prescription'=>'Rezept', 'referral'=>'Überweisung', 'appointment'=>'Termin',
        'callback'=>'Rückrufwunsch', 'other'=>'Sonstiges', 'opening_hours'=>'Öffnungszeiten',
        'pharmacy'=>'Apotheke', 'specialist'=>'Fachstelle',
    ];
    private const APPLICATION_ID = 1262900052; // KFST
    private ?\PDO $db = null;
    private \Closure $clock;
    private \Closure $logger;
    private \DateTimeZone $zone;
    private bool $enabled;
    private bool $online = false;
    private ?int $session = null;
    private ?float $faultSince = null;
    private bool $storageFailed = false;
    private float $demoAsOf;

    public function __construct(private array $config, private bool $demo = false, ?callable $clock = null, ?callable $logger = null)
    {
        $this->clock = \Closure::fromCallable($clock ?? static fn() => microtime(true));
        $this->logger = \Closure::fromCallable($logger ?? static fn(string $message) => error_log($message));
        $this->zone = new \DateTimeZone('Europe/Berlin');
        $this->enabled = ($config['enabled'] ?? false) && ($config['pbx_id'] ?? '') !== '';
        $this->demoAsOf = $this->now();
        if ($this->enabled && !$demo) {
            try { $this->open(); } catch (\Throwable $error) { $this->failure($error); }
        }
    }

    private function now(): float { return (float)($this->clock)(); }
    private function date(float $time): \DateTimeImmutable { return (new \DateTimeImmutable('@' . (int)floor($time)))->setTimezone($this->zone); }

    private function open(): void
    {
        $path = $this->config['database_path'];
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException('Statistikpfad ist keine reguläre Datei.');
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) throw new \RuntimeException('Statistikverzeichnis fehlt oder ist nicht schreibbar.');
        $mask = umask(0077);
        try { $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]); }
        finally { umask($mask); }
        $db->exec('PRAGMA busy_timeout=25');
        $db->exec('PRAGMA foreign_keys=ON');
        $id = (int)$db->query('PRAGMA application_id')->fetchColumn();
        $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
        if ($id !== self::APPLICATION_ID && ($id !== 0 || $version !== 0 || (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn() !== 0)) {
            throw new \RuntimeException('Datei gehört nicht zur Anrufstatistik.');
        }
        if ($id === self::APPLICATION_ID && $version !== 1) throw new \RuntimeException('Unbekannte Statistikversion.');
        if (!chmod($path, 0600)) throw new \RuntimeException('Statistikrechte konnten nicht gesetzt werden.');
        $db->exec('PRAGMA synchronous=FULL');
        if ($id === 0) {
            $db->beginTransaction();
            try {
                $db->exec('CREATE TABLE calls (pbx TEXT NOT NULL, id TEXT NOT NULL, entered_at INTEGER NOT NULL, day TEXT NOT NULL, PRIMARY KEY(pbx,id))');
                $db->exec('CREATE INDEX calls_day ON calls(pbx,day)');
                $db->exec('CREATE TABLE routes (pbx TEXT NOT NULL, call_id TEXT NOT NULL, route TEXT NOT NULL, PRIMARY KEY(pbx,call_id,route), FOREIGN KEY(pbx,call_id) REFERENCES calls(pbx,id))');
                $db->exec('CREATE TABLE capture (id INTEGER PRIMARY KEY, pbx TEXT NOT NULL, started REAL NOT NULL, as_of REAL NOT NULL, ended REAL)');
                $db->exec('CREATE INDEX capture_pbx ON capture(pbx,started)');
                $db->exec('CREATE TABLE gaps (id INTEGER PRIMARY KEY, pbx TEXT NOT NULL, started REAL NOT NULL, ended REAL NOT NULL)');
                $db->exec('PRAGMA application_id=' . self::APPLICATION_ID);
                $db->exec('PRAGMA user_version=1');
                $db->commit();
            } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        }
        $this->db = $db;
    }

    private function query(string $sql, array $args = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    private function failure(\Throwable $error): void
    {
        if (!$this->storageFailed) ($this->logger)('Anrufstatistik: ' . $error->getMessage());
        $this->storageFailed = true;
        $this->faultSince ??= $this->now();
        // Never extend the old capture interval across a failed write/read.
        $this->session = null;
    }

    private function write(callable $operation): void
    {
        if (!$this->enabled || $this->demo) return;
        try {
            if ($this->db === null) $this->open();
            $this->db->beginTransaction();
            $now = $this->now();
            if ($this->faultSince !== null) $this->query('INSERT INTO gaps(pbx,started,ended) VALUES(?,?,?)', [$this->config['pbx_id'], $this->faultSince, max($now, $this->faultSince)]);
            if ($this->online && $this->session === null) {
                $this->query('INSERT INTO capture(pbx,started,as_of) VALUES(?,?,?)', [$this->config['pbx_id'], $now, $now]);
                $this->session = (int)$this->db->lastInsertId();
            }
            $operation();
            $this->db->commit();
            $this->storageFailed = false;
            $this->faultSince = null;
        } catch (\Throwable $error) {
            if ($this->db?->inTransaction()) $this->db->rollBack();
            $this->failure($error);
        }
    }

    public function connected(): void
    {
        $this->online = true;
        $this->checkpoint();
    }

    public function checkpoint(): void
    {
        if (!$this->online) return;
        $this->write(function () { $this->advance(); });
    }

    private function advance(): void
    {
        if ($this->session !== null) $this->query('UPDATE capture SET as_of=MAX(as_of,?) WHERE id=?', [$this->now(), $this->session]);
    }

    public function disconnected(): void
    {
        if (!$this->online) return;
        $this->online = false;
        $this->write(function () {
            if ($this->session !== null) $this->query('UPDATE capture SET ended=? WHERE id=?', [$this->now(), $this->session]);
        });
        $this->session = null;
    }

    public function stop(): void { $this->disconnected(); }

    public function event(array $event): void
    {
        if (!$this->enabled || $this->demo || ($event['Event'] ?? '') !== 'UserEvent'
            || !in_array($event['UserEvent'] ?? '', ['KienzlefonCallEntered','KienzlefonCallRoute'], true)
            || ($event['SchemaVersion'] ?? '') !== '1' || ($event['PbxId'] ?? '') !== $this->config['pbx_id']) return;
        $id = $event['CallId'] ?? ''; $entered = $event['EnteredAt'] ?? '';
        if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)
            || !is_string($entered) || !preg_match('/^[0-9]{9,11}$/D', $entered)
            || (int)$entered < 946684800 || (int)$entered > $this->now() + 5) return;
        $route = $event['UserEvent'] === 'KienzlefonCallRoute' ? ($event['Route'] ?? '') : null;
        if ($route !== null && (!is_string($route) || !isset(self::ROUTES[$route]))) return;
        $id = strtolower($id); $entered = (int)$entered;
        $this->write(function () use ($id, $entered, $route) {
            $pbx = $this->config['pbx_id'];
            $this->query('INSERT INTO calls(pbx,id,entered_at,day) VALUES(?,?,?,?) ON CONFLICT(pbx,id) DO NOTHING', [$pbx, $id, $entered, $this->date($entered)->format('Y-m-d')]);
            $original = (int)$this->query('SELECT entered_at FROM calls WHERE pbx=? AND id=?', [$pbx, $id])->fetchColumn();
            if ($original !== $entered) {
                $this->query('INSERT INTO gaps(pbx,started,ended) VALUES(?,?,?)', [$pbx, $this->now(), $this->now()]);
                ($this->logger)('Anrufstatistik: Widersprüchlicher Eingangszeitpunkt im Ereignis.');
                return;
            }
            if ($route !== null) $this->query('INSERT INTO routes(pbx,call_id,route) VALUES(?,?,?) ON CONFLICT(pbx,call_id,route) DO NOTHING', [$pbx, $id, $route]);
            $this->advance();
        });
    }

    public function view(): array
    {
        $now = $this->demo ? $this->demoAsOf : $this->now(); $start = $this->date($now)->setTime(0, 0); $end = $start->modify('+1 day');
        $today = ['date'=>$start->format('Y-m-d'), 'timezone'=>'Europe/Berlin', 'period_start'=>$start->format('c'), 'period_end'=>$end->format('c'),
            'incoming_calls'=>null, 'status'=>'unavailable', 'as_of'=>null, 'reason_code'=>'not_configured', 'routes'=>null, 'unclassified_calls'=>null];
        if ($this->demo) {
            $today['incoming_calls'] = 47; $today['status'] = 'complete'; $today['reason_code'] = null;
            $today['as_of'] = $this->date($this->demoAsOf)->format('c');
            $today['routes'] = $this->routeRows(['ivr'=>40,'ai'=>12,'queue'=>23,'prescription'=>8,'referral'=>3,'appointment'=>6,'callback'=>4,'other'=>2,'opening_hours'=>5,'pharmacy'=>2,'specialist'=>1]);
            $today['unclassified_calls'] = 2;
        } elseif ($this->enabled) {
            $today['reason_code'] = 'storage_unavailable';
            if ($this->db !== null && !$this->storageFailed) {
                try {
                    // A consistent read transaction; no checkpoint, reset or maintenance on GET.
                    $this->db->beginTransaction();
                    $pbx = $this->config['pbx_id']; $day = $today['date'];
                    $count = (int)$this->query('SELECT COUNT(*) FROM calls WHERE pbx=? AND day=?', [$pbx, $day])->fetchColumn();
                    $routes = $this->query('SELECT r.route,COUNT(*) AS n FROM routes r JOIN calls c ON c.pbx=r.pbx AND c.id=r.call_id WHERE c.pbx=? AND c.day=? GROUP BY r.route', [$pbx, $day])->fetchAll(\PDO::FETCH_KEY_PAIR);
                    $unclassified = (int)$this->query('SELECT COUNT(*) FROM calls c WHERE pbx=? AND day=? AND NOT EXISTS(SELECT 1 FROM routes r WHERE r.pbx=c.pbx AND r.call_id=c.id)', [$pbx, $day])->fetchColumn();
                    $first = $this->query('SELECT MIN(started) FROM capture WHERE pbx=?', [$pbx])->fetchColumn();
                    $asOf = $this->query('SELECT MAX(as_of) FROM capture WHERE pbx=?', [$pbx])->fetchColumn();
                    $intervals = $this->query('SELECT started,as_of FROM capture WHERE pbx=? AND as_of>=? AND started<? ORDER BY started', [$pbx, $start->getTimestamp(), $end->getTimestamp()])->fetchAll(\PDO::FETCH_ASSOC);
                    $explicitGap = (bool)$this->query('SELECT 1 FROM gaps WHERE pbx=? AND started<? AND ended>=? LIMIT 1', [$pbx, $end->getTimestamp(), $start->getTimestamp()])->fetchColumn();
                    $this->db->commit();
                    $initial = $first === null || (float)$first > $start->getTimestamp();
                    $covered = $initial && $first !== null ? (float)$first : (float)$start->getTimestamp();
                    $gap = $explicitGap || (!$this->online && $first !== null);
                    foreach ($intervals as $interval) {
                        if ((float)$interval['started'] > $covered) $gap = true;
                        $covered = max($covered, (float)$interval['as_of']);
                    }
                    if ($asOf === null || (float)$asOf < $start->getTimestamp()) $gap = !$initial || $gap;
                    $today['incoming_calls'] = $count;
                    $today['status'] = $gap || $initial ? 'partial' : 'complete';
                    $today['reason_code'] = $gap ? 'capture_gap' : ($initial ? 'initial_partial_day' : null);
                    $today['as_of'] = $asOf === null ? null : $this->date((float)$asOf)->format('c');
                    $today['routes'] = $this->routeRows($routes);
                    $today['unclassified_calls'] = $unclassified;
                } catch (\Throwable $error) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    $this->failure($error);
                }
            }
        }
        return ['today'=>$today];
    }

    private function routeRows(array $counts): array
    {
        $rows = [];
        foreach (self::ROUTES as $id => $label) $rows[] = ['id'=>$id, 'label'=>$label, 'incoming_calls'=>(int)($counts[$id] ?? 0)];
        return $rows;
    }
}
