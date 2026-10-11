<?php
declare(strict_types=1);
namespace Kienzlefon;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

final class Runtime
{
    private Telephony $state;
    private ?Ami $ami = null;
    private ?Aps $aps = null;
    private Gdt $gdt;
    private array $lookups = [];
    private array $phoneLookups = [];
    private array $jobs = [];
    private array $outgoing = [];
    private array $gdtRequests = [];
    private bool $syncing = false;
    public function __construct(public readonly array $config, public readonly bool $demo = false)
    {
        $this->state = new Telephony($config['telephony']);
        $this->gdt = new Gdt($config['gdt'], $config['clients']);
        if (!$demo) { $this->ami = new Ami($config['ami']); $this->aps = new Aps($config['aps'], $config['telephony']['country_code']); }
    }
    public function start(): void
    {
        if (!$this->ami) return;
        $this->ami->on('connected', fn() => $this->synchronize());
        $this->ami->on('disconnected', function () {
            $this->syncing = false; $this->state->disconnect(); $this->jobs = [];
            foreach ($this->outgoing as &$call) if (!in_array($call['phase'], ['ended','failed'], true)) $call['phase'] = 'unknown';
            unset($call);
        });
        $this->ami->on('event', function ($e) { $this->state->event($e); $this->onEvent($e); });
        $this->ami->start();
        Loop::addPeriodicTimer(1, fn() => $this->refreshLookups());
        Loop::addPeriodicTimer(60, function () { $this->synchronize(); $this->collect(); });
    }
    public function stop(): void { $this->ami?->stop(); }
    private function synchronize(): void
    {
        if ($this->syncing || !$this->ami?->connected()) return;
        $this->syncing = true; $this->state->beginSync();
        all([$this->ami->action('CoreShowChannels', [], 'CoreShowChannelsComplete'), $this->ami->action('QueueStatus', [], 'QueueStatusComplete')])->then(function ($snapshots) {
            $this->state->finishSync($snapshots[0], $snapshots[1]); $this->syncing = false; $this->refreshLookups();
        }, function () { $this->syncing = false; $this->ami?->disconnect(); });
    }
    private function refreshLookups(): void
    {
        foreach ($this->state->view()['calls'] as $call) if ($call['number'] !== '') $this->lookup($call['number'])->then(null, static fn() => null);
    }
    public function lookup(string $number): PromiseInterface
    {
        $number = Phone::normalize($number);
        if (Phone::external($number, $this->config['telephony']['extensions']) === '') throw new \InvalidArgumentException('Bitte eine vollständige externe Rufnummer eingeben.');
        $key = Phone::key($number, $this->config['telephony']['country_code']);
        $id = $this->phoneLookups[$key] ?? null;
        if ($id && isset($this->lookups[$id]) && $this->lookups[$id]['expires'] > microtime(true)) return $this->lookups[$id]['promise'];
        if (count($this->lookups) >= 512) { $this->collect(); if (count($this->lookups) >= 512) throw new \RuntimeException('Zu viele Suchaufträge. Bitte kurz warten.'); }
        $id = bin2hex(random_bytes(16));
        $this->phoneLookups[$key] = $id;
        $this->lookups[$id] = ['expires'=>microtime(true) + max(5, $this->config['aps']['cache_seconds']), 'number'=>$number, 'result'=>['status'=>'pending','name'=>'','patients'=>[]]];
        $promise = $this->demo ? resolve($this->demoLookup($number)) : $this->aps->lookup($number);
        $promise = $promise->then(function ($result) use ($id, $number) {
            if (isset($this->lookups[$id])) $this->lookups[$id]['result'] = $result;
            return ['lookup_id'=>$id,'number'=>$number] + $result;
        }, function (\Throwable $error) use ($id, $number) {
            $result = ['status'=>$error->getCode() === 408 ? 'timeout' : 'error', 'name'=>'', 'patients'=>[], 'message'=>'APS-Abfrage fehlgeschlagen. Verbindung und Zugang prüfen.'];
            if (isset($this->lookups[$id])) { $this->lookups[$id]['result'] = $result; $this->lookups[$id]['expires'] = microtime(true) + 5; }
            return ['lookup_id'=>$id, 'number'=>$number] + $result;
        });
        $this->lookups[$id]['promise'] = $promise;
        return $promise;
    }
    public function openPatient(array $input): array
    {
        $client = $this->client($input['client_id'] ?? '');
        $request = self::requestId($input['request_id'] ?? '');
        $lookupId = $input['lookup_id'] ?? '';
        $number = $input['patient_number'] ?? null;
        if (!is_string($lookupId) || !is_int($number)) throw new \InvalidArgumentException('Ungültige Patientenauswahl.');
        $key = $input['client_id'] . ':' . $request;
        $signature = $lookupId . ':' . $number;
        if (isset($this->gdtRequests[$key])) {
            if ($this->gdtRequests[$key]['signature'] !== $signature) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
            return $this->gdtRequests[$key]['result'];
        }
        $lookup = $this->lookups[$lookupId] ?? null;
        if (!$lookup || $lookup['expires'] <= microtime(true)) throw new \InvalidArgumentException('Patientensuche ist abgelaufen. Bitte erneut suchen.');
        $matches = array_values(array_filter($lookup['result']['patients'], fn($p) => $p['patient_number'] === $number));
        if (count($matches) !== 1) throw new \InvalidArgumentException('Patient ist in dieser Suche nicht eindeutig auswählbar.');
        if (count($this->gdtRequests) >= 4096) throw new \RuntimeException('Zu viele Aktenaufrufe. Bitte kurz warten.');
        $result = $this->demo ? ['status'=>'demo','message'=>'Demo: Aktenaufruf simuliert, keine Datei geschrieben.'] : $this->gdt->write($input['client_id'], $number);
        $result['client_id'] = $input['client_id'];
        $result['request_id'] = $request;
        $this->gdtRequests[$key] = ['signature'=>$signature,'result'=>$result,'expires'=>microtime(true) + 600];
        return $result;
    }
    public function originate(array $input): PromiseInterface
    {
        $clientId = $input['client_id'] ?? '';
        $client = $this->client($clientId);
        if (($input['extension'] ?? '') !== $client['extension']) throw new \InvalidArgumentException('Nebenstelle passt nicht zum Arbeitsplatz.');
        $number = Phone::normalize($input['number'] ?? '');
        $id = self::requestId($input['request_id'] ?? '');
        $signature = $clientId . ':' . $number;
        if (isset($this->outgoing[$id])) {
            if ($this->outgoing[$id]['_signature'] !== $signature) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
            return resolve($this->publicOutgoing($this->outgoing[$id]));
        }
        if (!$this->demo && !$this->ami?->connected()) throw new \RuntimeException('AMI ist nicht verbunden.');
        foreach ($this->outgoing as $call) if ($call['extension'] === $client['extension'] && in_array($call['phase'], ['requested','ringing_extension','dialing_target','ringing_target','connected','unknown'], true) && $call['_expires'] > microtime(true)) throw new \RuntimeException('Für diese Nebenstelle ist bereits ein Auftrag aktiv. Bei ungewissem Ergebnis zuerst das Telefon prüfen.');
        if (count($this->outgoing) >= 100) throw new \RuntimeException('Zu viele Telefonieaufträge. Bitte kurz warten.');
        $this->outgoing[$id] = ['request_id'=>$id,'client_id'=>$clientId,'extension'=>$client['extension'],'number'=>$number,'phase'=>'requested','created_at'=>gmdate('c'),'_signature'=>$signature,'_source'=>'kf-' . $id,'_target'=>'','_legs'=>[],'_answered'=>false,'_ever_connected'=>false,'_expires'=>microtime(true) + 600];
        if ($this->demo) { $this->outgoing[$id]['phase'] = 'demo'; return resolve($this->publicOutgoing($this->outgoing[$id])); }
        return $this->ami->action('Originate', [
            'Channel'=>'PJSIP/' . $client['extension'], 'ChannelId'=>'kf-' . $id,
            'Context'=>$this->config['telephony']['originate_context'], 'Exten'=>$number, 'Priority'=>'1', 'Async'=>'true', 'EarlyMedia'=>'false',
            'Timeout'=>$this->config['telephony']['originate_timeout_ms'], 'CallerID'=>'Rueckruf <' . $number . '>',
            'Variable'=>['__KF_C2D_ID=' . $id, '__KF_C2D_CLIENT=' . $clientId, '__KF_C2D_EXTENSION=' . $client['extension']],
        ], null, 'kf-c2d-' . $id)->then(function () use ($id) {
            if ($this->outgoing[$id]['phase'] === 'requested') $this->outgoing[$id]['phase'] = 'ringing_extension';
            return $this->publicOutgoing($this->outgoing[$id]);
        }, function () use ($id) {
            // A lost acknowledgement cannot prove that Originate was not executed.
            $this->outgoing[$id]['phase'] = 'unknown';
            return $this->publicOutgoing($this->outgoing[$id]);
        });
    }
    private function onEvent(array $e): void
    {
        $event = $e['Event'] ?? '';
        if ($event === 'UserEvent' && ($e['UserEvent'] ?? '') === 'KienzlefonLookupRequest') $this->phoneName($e);
        if ($event === 'UserEvent' && ($e['UserEvent'] ?? '') === 'KienzlefonLookupFinished') unset($this->jobs[$e['SourceUniqueid'] ?? '']);
        if ($event === 'Hangup') unset($this->jobs[$e['Uniqueid'] ?? '']);
        foreach ($this->outgoing as $id => &$call) {
            if (in_array($call['phase'], ['ended','failed','demo'], true)) continue;
            if ($event === 'OriginateResponse' && ($e['ActionID'] ?? '') === 'kf-c2d-' . $id) {
                if (($e['Response'] ?? '') === 'Failure') $call['phase'] = 'failed';
                elseif (($e['Response'] ?? '') === 'Success' && !in_array($call['phase'], ['ringing_target','connected'], true)) $call['phase'] = 'dialing_target';
                if (isset($e['Uniqueid']) && $e['Uniqueid'] !== '<null>') $call['_source'] = $e['Uniqueid'];
            }
            if ($event === 'UserEvent' && ($e['UserEvent'] ?? '') === 'KienzlefonClickToDialProgress' && ($e['RequestId'] ?? '') === $id && ($e['Phase'] ?? '') === 'dialing_target') {
                $call['phase'] = 'dialing_target'; $call['_source'] = $e['SourceUniqueid'] ?? $call['_source'];
            }
            if ($event === 'DialBegin' && !$call['_ever_connected'] && (
                ($e['Uniqueid'] ?? '') === $call['_source'] || ($e['Linkedid'] ?? '') === $call['_source'] || in_array($e['Uniqueid'] ?? '', $call['_legs'], true)
            )) {
                $dest = $e['DestUniqueid'] ?? '';
                if ($dest !== '') $call['_legs'][] = $dest;
                if (!str_starts_with($e['DestChannel'] ?? '', 'Local/')) $call['_target'] = $dest;
                $call['phase'] = 'ringing_target';
            }
            if ($event === 'DialEnd' && $call['_target'] !== '' && ($e['DestUniqueid'] ?? '') === $call['_target']) {
                $call['_answered'] = ($e['DialStatus'] ?? '') === 'ANSWER';
                if (!$call['_answered']) $call['phase'] = 'failed';
            }
            if ($call['_target'] !== '' && $this->state->bridged($call['_source'], $call['_target'])) {
                // Bridge of the locally answered leg and the actual outgoing leg is connection evidence.
                $call['phase'] = 'connected'; $call['_ever_connected'] = true;
            }
            if ($event === 'Hangup' && in_array($e['Uniqueid'] ?? '', [$call['_source'],$call['_target']], true)) $call['phase'] = $call['_ever_connected'] ? 'ended' : 'failed';
        }
        unset($call);
    }
    private function phoneName(array $e): void
    {
        if (($e['SchemaVersion'] ?? '') !== '1') return;
        $uid = $e['SourceUniqueid'] ?? ''; $channel = $e['SourceChannel'] ?? ''; $request = $e['RequestId'] ?? '';
        if (!$uid || !$channel || !$request || strlen($request) > 128 || preg_match('/[\x00-\x1f\x7f]/', $uid . $channel . $request)) return;
        if (isset($this->jobs[$uid]) || count($this->jobs) >= 256) return;
        $number = Phone::external($e['Number'] ?? '', $this->config['telephony']['extensions']);
        if (!$number) return;
        $deadline = microtime(true) + min(0.9, max(0, (int)($e['BudgetMs'] ?? 1000)) / 1000);
        $this->jobs[$uid] = ['id'=>$request, 'deadline'=>$deadline];
        Loop::addTimer(1.1, function () use ($uid, $request) { if (($this->jobs[$uid]['id'] ?? '') === $request) unset($this->jobs[$uid]); });
        try { $lookup = $this->lookup($number); } catch (\Throwable) { unset($this->jobs[$uid]); return; }
        $lookup->then(function ($result) use ($uid, $request, $deadline, $channel) {
            $status = in_array($result['status'], ['resolved','ambiguous','not_found'], true) ? $result['status'] : 'error';
            $vars = ['LOOKUP_RESULT_ID'=>$request,'LOOKUP_NAME'=>$status === 'resolved' ? $result['name'] : '', 'LOOKUP_STATUS'=>$status,'LOOKUP_DONE'=>'1'];
            $p = resolve(null);
            foreach ($vars as $key => $value) $p = $p->then(function () use ($uid, $request, $deadline, $channel, $key, $value) {
                if (($this->jobs[$uid]['id'] ?? '') !== $request || microtime(true) >= $deadline || !$this->ami?->connected()) throw new \RuntimeException('Lookup beendet.');
                return $this->ami->action('Setvar', ['Channel'=>$channel, 'Variable'=>$key, 'Value'=>$value]);
            });
            $p->then(static fn() => null, static fn() => null);
        }, static fn() => null);
    }
    public function view(): array
    {
        $view = $this->demo ? $this->demoView() : $this->state->view();
        if (!$this->demo) foreach ($view['calls'] as &$call) {
            if ($call['number'] === '') continue;
            $key = Phone::key($call['number'], $this->config['telephony']['country_code']);
            $id = $this->phoneLookups[$key] ?? null;
            if ($id && isset($this->lookups[$id])) {
                $lookup = $this->lookups[$id];
                if ($lookup['expires'] > microtime(true)) {
                    $call['lookup_id'] = $id; $call['name_status'] = $lookup['result']['status'];
                    $call['patients'] = Aps::publicPatients($lookup['result']['patients']);
                    $call['name'] = $lookup['result']['name'];
                }
            }
        }
        unset($call);
        $view['outgoing'] = array_values(array_map($this->publicOutgoing(...), $this->outgoing));
        return $view;
    }
    private function publicOutgoing(array $call): array { return array_filter($call, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY); }
    private function client(mixed $id): array
    {
        if (!is_string($id) || !isset($this->config['clients'][$id])) throw new \InvalidArgumentException('Bitte einen registrierten Arbeitsplatz wählen.');
        return $this->config['clients'][$id];
    }
    private static function requestId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id)) throw new \InvalidArgumentException('Ungültige Vorgangs-ID.');
        return strtolower($id);
    }
    private function collect(): void
    {
        $now = microtime(true);
        foreach ($this->lookups as $id => $entry) if ($entry['expires'] <= $now) unset($this->lookups[$id]);
        foreach ($this->phoneLookups as $phone => $id) if (!isset($this->lookups[$id])) unset($this->phoneLookups[$phone]);
        foreach ($this->gdtRequests as $id => $entry) if ($entry['expires'] <= $now) unset($this->gdtRequests[$id]);
        foreach ($this->outgoing as $id => $entry) if ($entry['_expires'] <= $now && in_array($entry['phase'], ['ended','failed','unknown','demo','ringing_extension','requested'], true)) unset($this->outgoing[$id]);
    }
    private function demoLookup(string $number): array
    {
        $one = ['patient_number'=>90001,'first_name'=>'Clara','last_name'=>'Sommer','birth_date'=>'19840412','address'=>'Beispielstraße 12, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-1'],'revision'=>1]];
        $two = ['patient_number'=>90002,'first_name'=>'Paul','last_name'=>'Becker','birth_date'=>'19770803','address'=>'Musterweg 5, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-2'],'revision'=>1]];
        $three = ['patient_number'=>90003,'first_name'=>'Anna','last_name'=>'Becker','birth_date'=>'19810319','address'=>'Musterweg 5, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-3'],'revision'=>1]];
        if (str_ends_with($number, '000')) return ['status'=>'not_found','name'=>'','patients'=>[]];
        $patients = str_ends_with($number, '22') ? [$two,$three] : [$one];
        return ['status'=>count($patients) === 1 ? 'resolved' : 'ambiguous','name'=>count($patients) === 1 ? Aps::displayName($one) : '', 'patients'=>$patients];
    }
    private function demoView(): array
    {
        $calls = [];
        foreach ([['demo-1','03055501011','waiting',[],74],['demo-2','03055501022','waiting',[],31],['demo-3','03055501033','connected',['201'],143]] as [$id,$number,$state,$extensions,$duration]) {
            $this->lookup($number)->then(static fn() => null);
            $key = Phone::key($number); $lookupId = $this->phoneLookups[$key]; $result = $this->lookups[$lookupId]['result'];
            $calls[] = ['id'=>$id,'number'=>$number,'direction'=>'incoming','state'=>$state,'extensions'=>$extensions,'channel_age_seconds'=>$duration,'duration_seconds'=>$state === 'connected' ? $duration : null,'name_status'=>$result['status'],'name'=>$result['name'],'lookup_id'=>$lookupId,'patients'=>Aps::publicPatients($result['patients'])];
        }
        return ['generated_at'=>gmdate('c'),'ami_connected'=>true,'stale'=>false,'calls'=>$calls,'queues'=>[['name'=>'praxis','waiting_count'=>2,'entries'=>[['call_id'=>'demo-1','position'=>1,'wait_seconds'=>74],['call_id'=>'demo-2','position'=>2,'wait_seconds'=>31]]]],'extensions'=>[['id'=>'201','label'=>'Empfang 1','state'=>'busy','paused'=>false],['id'=>'202','label'=>'Empfang 2','state'=>'idle','paused'=>false]]];
    }
}
