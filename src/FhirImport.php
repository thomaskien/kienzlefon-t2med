<?php
declare(strict_types=1);
namespace Kienzlefon;

use React\EventLoop\Loop;

/** Owns the one-shot child process. Credentials exist only in the incoming request and pipe. */
final class FhirImport
{
    private array $job = ['state'=>'idle'];
    private array $seen = [];
    private mixed $process = null;
    private mixed $lock = null;
    private array $pipes = [];
    private mixed $timer = null;
    private string $buffer = '';
    private int $bytes = 0;
    private ?array $completed = null;
    private float $deadline = 0;
    private ?int $exitCode = null;
    public function __construct(private array $config, private PatientDirectory $directory, private \Closure $onComplete)
    {
        $path = $config['database'] . '.status.json';
        if (is_file($path) && !is_link($path)) {
            try {
                $job = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                if (is_array($job) && in_array($job['state'] ?? '', ['running','success','error'], true)) {
                    $this->job = array_intersect_key($job, array_flip(['id','state','started_at','finished_at','pages','patient_count','total','message']));
                    if ($this->job['state'] === 'running') { $this->job['state'] = 'error'; $this->job['message'] = 'Abgleich durch Dienstneustart unterbrochen. Bitte erneut aus T2med starten.'; }
                }
            } catch (\Throwable) { /* No credential-bearing exception is exposed. */ }
        }
    }
    public function status(): array { return ['provider'=>'fhir','job'=>$this->job,'directory'=>$this->directory->status()]; }
    public function start(array $input): array
    {
        foreach (['kontextId','fhirBasisUrl','oAuthToken'] as $field) {
            Config::text($input[$field] ?? null, $field === 'oAuthToken' ? 8192 : ($field === 'fhirBasisUrl' ? 2048 : 256));
            if (str_contains($input[$field], '${')) throw new \InvalidArgumentException('T2med-Platzhalter wurden nicht ersetzt. Bitte den Aufruf in T2med starten.');
        }
        if (rtrim($input['fhirBasisUrl'], '/') !== rtrim($this->config['base_url'], '/')) throw new \InvalidArgumentException('FHIR-Ziel stimmt nicht mit der freigegebenen Serveradresse überein.');
        if (preg_match('/\s/', $input['oAuthToken'])) throw new \InvalidArgumentException('Ungültige Token-Übergabe. Bitte URL-Kodierung im T2med-Aufruf prüfen.');
        $requestId = $input['request_id'] ?? '';
        if (!is_string($requestId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $requestId)) throw new \InvalidArgumentException('Ungültige Abgleichskennung.');
        $fingerprint = hash('sha256', json_encode([$input['kontextId'],$input['fhirBasisUrl'],$input['oAuthToken']], JSON_THROW_ON_ERROR));
        $now = microtime(true);
        foreach ($this->seen as $key=>$attempt) if ($attempt['expires'] < $now) unset($this->seen[$key]);
        if (isset($this->seen[$requestId])) {
            if ($this->seen[$requestId]['signature'] !== $fingerprint) throw new \InvalidArgumentException('Abgleichskennung wurde bereits anders verwendet.');
            return array_replace($this->status(), ['job'=>$this->seen[$requestId]['job'] ?? $this->job,'accepted'=>false,'duplicate'=>true]);
        }
        if ($this->process !== null) throw new \RuntimeException('Ein Abgleich läuft bereits. Bitte dessen Abschluss abwarten.');
        if (!is_file($this->config['binary']) || !is_executable($this->config['binary'])) throw new \RuntimeException('FHIR-Import-Binary ist nicht installiert oder nicht ausführbar.');
        $parent = dirname($this->config['database']);
        if (is_link($parent) || (!is_dir($parent) && !@mkdir($parent, 0700, true))) throw new \RuntimeException('Geschütztes Verzeichnis für den Patientenbestand nicht verfügbar.');
        $lockPath = $this->config['database'] . '.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Importsperre ist nicht verfügbar.');
        $this->lock = @fopen($lockPath, 'c');
        if ($this->lock === false || !@chmod($lockPath, 0600) || !flock($this->lock, LOCK_EX | LOCK_NB)) { $this->releaseLock(); throw new \RuntimeException('Ein Abgleich läuft bereits oder die Importsperre ist nicht verfügbar.'); }
        $this->job = ['id'=>$requestId,'state'=>'running','started_at'=>gmdate('c'),'finished_at'=>null,'pages'=>0,'patient_count'=>0,'total'=>null,'message'=>'Patienten werden aus T2med geladen.'];
        $this->buffer = ''; $this->bytes = 0; $this->completed = null; $this->exitCode = null;
        try {
            $this->process = @proc_open([$this->config['binary']], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']], $this->pipes, null, ['PATH'=>'/usr/bin:/bin']);
            if (!is_resource($this->process)) { $this->process = null; throw new \RuntimeException(); }
            $request = json_encode(['base_url'=>$this->config['base_url'],'context_id'=>$input['kontextId'],'token'=>$input['oAuthToken'],'tls_verify'=>$this->config['tls_verify'],'ca_file'=>$this->config['ca_file'],'timeout_seconds'=>$this->config['timeout_seconds'],'max_pages'=>$this->config['max_pages'],'max_patients'=>$this->config['max_patients']], JSON_THROW_ON_ERROR);
            if (@fwrite($this->pipes[0], $request) !== strlen($request)) throw new \RuntimeException();
            fclose($this->pipes[0]); unset($this->pipes[0], $request, $input);
            stream_set_blocking($this->pipes[1], false);
            $this->deadline = $now + 610;
            if (count($this->seen) >= 64) array_shift($this->seen);
            $this->seen[$requestId] = ['expires'=>$now + 900,'signature'=>$fingerprint];
            $this->saveStatus();
            $this->timer = Loop::addPeriodicTimer(0.1, fn() => $this->poll());
        } catch (\Throwable) { $this->fail('FHIR-Import konnte nicht gestartet werden.'); }
        return $this->status() + ['accepted'=>$this->job['state'] === 'running'];
    }
    private function poll(): void
    {
        try {
            if (microtime(true) > $this->deadline) throw new \RuntimeException('Zeitlimit für den FHIR-Abgleich überschritten.');
            $chunk = stream_get_contents($this->pipes[1]);
            if ($chunk === false) throw new \RuntimeException();
            $this->bytes += strlen($chunk);
            if ($this->bytes > 128 * 1024 * 1024) throw new \RuntimeException('FHIR-Bestand überschreitet die erlaubte Importgröße.');
            $this->buffer .= $chunk;
            while (($end = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $end); $this->buffer = substr($this->buffer, $end + 1);
                $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($event) || $this->completed !== null) throw new \RuntimeException();
                if (($event['type'] ?? '') === 'progress') {
                    foreach (['pages','patient_count','total'] as $field) {
                        if (!is_int($event[$field] ?? null) || $event[$field] < ($field === 'total' ? -1 : 0)) throw new \RuntimeException();
                        $this->job[$field] = $event[$field] === -1 ? null : $event[$field];
                    }
                } elseif (($event['type'] ?? '') === 'complete') {
                    if (!is_array($event['patients'] ?? null) || !array_is_list($event['patients']) || count($event['patients']) !== ($event['total'] ?? null) || count($event['patients']) > $this->config['max_patients']) throw new \RuntimeException();
                    $this->completed = $event;
                } elseif (($event['type'] ?? '') === 'error') {
                    $messages = ['auth'=>'FHIR-Zugang abgewiesen oder abgelaufen. Bitte erneut aus T2med starten.','network'=>'FHIR-Server nicht erreichbar oder Zertifikatsprüfung fehlgeschlagen.','invalid_data'=>'FHIR-Bestand ist unvollständig oder widersprüchlich.','limit'=>'FHIR-Abgleich hat ein Größen- oder Zeitlimit erreicht.','config'=>'FHIR-Import-Binary oder Serverkonfiguration ist unvollständig.'];
                    $this->fail($messages[$event['code'] ?? ''] ?? 'FHIR-Abgleich fehlgeschlagen.'); return;
                } else throw new \RuntimeException();
            }
            $state = proc_get_status($this->process);
            if ($state['running']) return;
            if ($this->exitCode === null) $this->exitCode = $state['exitcode'];
            // The pipe can still contain bytes produced just before child exit.
            if (!feof($this->pipes[1])) return;
            if ($this->exitCode !== 0 || $this->completed === null || trim($this->buffer) !== '') throw new \RuntimeException();
            $this->directory->replace($this->completed['patients']);
            $this->job = array_replace($this->job, ['state'=>'success','finished_at'=>gmdate('c'),'pages'=>$this->completed['pages'],'patient_count'=>$this->completed['total'],'total'=>$this->completed['total'],'message'=>'Telefonverzeichnis erfolgreich aktualisiert.']);
            ($this->onComplete)(); $this->cleanup(); $this->saveStatus();
        } catch (\Throwable) { $this->fail('FHIR-Abgleich fehlgeschlagen. Bisheriger vollständiger Bestand bleibt erhalten.'); }
    }
    private function fail(string $message): void
    {
        $this->job = array_replace($this->job, ['state'=>'error','finished_at'=>gmdate('c'),'message'=>$message]);
        $this->cleanup(); $this->saveStatus();
    }
    private function releaseLock(): void { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } $this->lock = null; }
    private function cleanup(): void
    {
        if (isset($this->seen[$this->job['id'] ?? ''])) $this->seen[$this->job['id']]['job'] = $this->job;
        if ($this->timer !== null) { Loop::cancelTimer($this->timer); $this->timer = null; }
        if (is_resource($this->process)) { $state = proc_get_status($this->process); if ($state['running']) proc_terminate($this->process, 9); }
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $this->pipes = [];
        if (is_resource($this->process)) proc_close($this->process);
        $this->process = null; $this->completed = null; $this->buffer = ''; $this->releaseLock();
    }
    private function saveStatus(): void
    {
        $path = $this->config['database'] . '.status.json'; $temp = $path . '.' . bin2hex(random_bytes(6));
        $old = umask(0077);
        try { if (@file_put_contents($temp, json_encode($this->job, JSON_THROW_ON_ERROR), LOCK_EX) !== false) @rename($temp, $path); }
        finally { umask($old); if (file_exists($temp)) @unlink($temp); }
    }
    public function stop(): void { if ($this->process !== null) $this->fail('Abgleich durch Dienststopp unterbrochen. Bitte erneut aus T2med starten.'); }
}
