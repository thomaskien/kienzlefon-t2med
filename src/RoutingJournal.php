<?php
declare(strict_types=1);
namespace Kienzlefon;

/** A committed intent always precedes transmission. No code replays POST from this journal. */
final class RoutingJournal
{
    private \PDO $db;
    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            if (is_link($path) || !is_dir(dirname($path)) || is_link(dirname($path))) throw new ControlError('state_unavailable');
            foreach ([$path,$path.'-wal',$path.'-shm',$path.'-journal'] as $file) if (is_link($file)) throw new ControlError('state_unavailable');
        }
        $mask=umask(0077);
        try {
            $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $this->db->exec('PRAGMA busy_timeout=25');
            $appId=(int)$this->db->query('PRAGMA application_id')->fetchColumn();
            $tables=(int)$this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn();
            if (($appId!==0 && $appId!==1262899794) || ($appId===0 && $tables!==0)) throw new ControlError('state_unavailable');
            if ($path!==':memory:' && !chmod($path,0600)) throw new ControlError('state_unavailable');
            if ($appId===0) $this->db->exec('PRAGMA application_id=1262899794');
            $this->db->exec('PRAGMA busy_timeout=25; PRAGMA synchronous=FULL; CREATE TABLE IF NOT EXISTS routing_requests (id TEXT PRIMARY KEY, command TEXT NOT NULL, job TEXT NOT NULL, terminal INTEGER NOT NULL, updated REAL NOT NULL)');
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                if (!in_array('paired_pbx', $this->db->query('PRAGMA table_info(routing_requests)')->fetchAll(\PDO::FETCH_COLUMN, 1), true)) {
                    $this->db->exec('ALTER TABLE routing_requests ADD COLUMN paired_pbx TEXT');
                }
                $this->db->exec('COMMIT');
            } catch (\Throwable $error) {
                $this->db->exec('ROLLBACK');
                throw $error;
            }
        } finally { umask($mask); }
    }
    public function get(string $id): ?array
    {
        $q=$this->db->prepare('SELECT command,job,paired_pbx FROM routing_requests WHERE id=?'); $q->execute([$id]); $r=$q->fetch(\PDO::FETCH_ASSOC);
        return $r ? ['paired_pbx'=>$r['paired_pbx'],'command'=>json_decode($r['command'],true,32,JSON_THROW_ON_ERROR),'job'=>json_decode($r['job'],true,32,JSON_THROW_ON_ERROR)] : null;
    }
    public function insert(array $command,array $job,float $now,?string $pairedPbx=null): void
    {
        $q=$this->db->prepare('INSERT INTO routing_requests (id,command,job,terminal,updated,paired_pbx) VALUES (?,?,?,0,?,?)');
        $q->execute([$command['request_id'],json_encode($command,JSON_THROW_ON_ERROR),json_encode($job,JSON_THROW_ON_ERROR),$now,$pairedPbx]);
    }
    public function update(array $job,float $now): void
    {
        // A late/invalid upstream reply cannot undo a confirmed result.
        $q=$this->db->prepare('UPDATE routing_requests SET job=?,terminal=?,updated=? WHERE id=? AND terminal=0');
        $q->execute([json_encode($job,JSON_THROW_ON_ERROR),(int)self::terminal($job),$now,$job['request_id']]);
    }
    public function pending(): array
    {
        return $this->db->query('SELECT id FROM routing_requests WHERE terminal=0')->fetchAll(\PDO::FETCH_COLUMN);
    }
    public function prune(float $now): void
    {
        $q=$this->db->prepare('DELETE FROM routing_requests WHERE terminal=1 AND updated<?'); $q->execute([$now-604800]);
    }
    public static function terminal(array $job): bool { return in_array($job['state'],['applied','rejected','expired'],true); }
}
