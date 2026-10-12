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
    private ?PatientDirectory $directory = null;
    private ?FhirImport $fhir = null;
    private Gdt $gdt;
    private Transfers $transfers;
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
        if (!$demo) {
            $this->ami = new Ami($config['ami']);
            if (($config['patients']['provider'] ?? 'fhir') === 'aps') $this->aps = new Aps($config['aps'], $config['telephony']['country_code']);
            else {
                $this->directory = new PatientDirectory($config['fhir'], $config['telephony']['country_code']);
                $this->fhir = new FhirImport($config['fhir'], $this->directory, function () { $this->lookups = []; $this->phoneLookups = []; });
            }
        }
        $this->transfers = new Transfers($this->state, fn(string $action, array $fields) => $this->ami->action($action,$fields), $config, $demo);
    }
    public function start(): void
    {
        Loop::addPeriodicTimer(60, function () { if ($this->ami) $this->synchronize(); $this->collect(); });
        if (!$this->ami) return;
        $this->ami->on('connected', fn() => $this->synchronize());
        $this->ami->on('disconnected', function () {
            $this->syncing = false; $this->state->disconnect(); $this->jobs = [];
            $this->transfers->disconnect();
            foreach ($this->outgoing as &$call) if (!in_array($call['phase'], ['ended','failed'], true)) $call['phase'] = 'unknown';
            unset($call);
        });
        $this->ami->on('event', function ($e) { $this->state->event($e); $this->onEvent($e); });
        $this->ami->start();
        Loop::addPeriodicTimer(1, fn() => $this->refreshLookups());
    }
    public function stop(): void { $this->fhir?->stop(); $this->ami?->stop(); }
    public function directoryStatus(): array { return $this->fhir?->status() ?? ['provider'=>$this->demo ? 'demo' : 'aps']; }
    public function importPatients(array $input): array
    {
        if ($this->fhir === null) throw new \RuntimeException('FHIR-Abgleich ist in diesem Betriebsmodus deaktiviert.');
        return $this->fhir->start($input);
    }
    private function synchronize(): void
    {
        if ($this->syncing || !$this->ami?->connected()) return;
        $this->syncing = true; $started = microtime(true); $this->state->beginSync();
        all([$this->ami->action('CoreShowChannels', [], 'CoreShowChannelsComplete'), $this->ami->action('QueueStatus', [], 'QueueStatusComplete')])->then(function ($snapshots) use ($started) {
            $this->state->finishSync($snapshots[0], $snapshots[1]); $this->syncing = false; $this->reconcileOutgoing($started); $this->transfers->event([]); $this->refreshLookups();
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
        $this->lookups[$id] = ['expires'=>microtime(true) + max(5, $this->config[$this->aps ? 'aps' : 'fhir']['cache_seconds']), 'number'=>$number, 'result'=>['status'=>'pending','name'=>'','patients'=>[]]];
        try { $promise = $this->demo ? resolve($this->demoLookup($number)) : ($this->aps ? $this->aps->lookup($number) : resolve($this->directory->lookup($number))); }
        catch (\Throwable $error) { $promise = \React\Promise\reject($error); }
        $promise = $promise->then(function ($result) use ($id, $number) {
            if (isset($this->lookups[$id])) $this->lookups[$id]['result'] = $result;
            return ['lookup_id'=>$id,'number'=>$number] + $result;
        }, function (\Throwable $error) use ($id, $number) {
            $result = ['status'=>$error->getCode() === 408 ? 'timeout' : 'error', 'name'=>'', 'patients'=>[], 'message'=>'Patientensuche nicht verfügbar. Verbindungs- beziehungsweise Importstatus prüfen.'];
            if (isset($this->lookups[$id])) { $this->lookups[$id]['result'] = $result; $this->lookups[$id]['expires'] = microtime(true) + 5; }
            return ['lookup_id'=>$id, 'number'=>$number] + $result;
        });
        $this->lookups[$id]['promise'] = $promise;
        return $promise;
    }
    public function searchPatients(string $query): PromiseInterface
    {
        $query = PatientDirectory::searchQuery($query);
        if (count($this->lookups) >= 512) { $this->collect(); if (count($this->lookups) >= 512) throw new \RuntimeException('Zu viele Suchaufträge. Bitte kurz warten.'); }
        $id = bin2hex(random_bytes(16));
        // Separate result IDs allow the existing checked GDT selection, without
        // adding names or patient-number matches to the telephone lookup cache.
        $this->lookups[$id] = ['expires'=>microtime(true) + max(5, $this->config[$this->aps ? 'aps' : 'fhir']['cache_seconds']), 'result'=>['status'=>'pending','name'=>'','patients'=>[]]];
        try {
            $promise = $this->demo ? resolve($this->demoSearch($query)) : ($this->aps
                ? $this->aps->search($query)->then(static fn($rows) => Aps::classify([$rows], []) + ['truncated'=>false])
                : resolve($this->directory->search($query)));
        } catch (\Throwable $error) { $promise = \React\Promise\reject($error); }
        $promise = $promise->then(function ($result) use ($id,$query) {
            if (isset($this->lookups[$id])) $this->lookups[$id]['result'] = $result;
            return ['lookup_id'=>$id,'query'=>$query] + $result;
        }, function (\Throwable $error) use ($id) {
            unset($this->lookups[$id]);
            throw new \RuntimeException('Patientensuche nicht verfügbar. Verbindungs- beziehungsweise Importstatus prüfen.');
        });
        if (isset($this->lookups[$id])) $this->lookups[$id]['promise'] = $promise;
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
        $rawNumber = $input['number'] ?? '';
        $number = is_string($rawNumber) && isset($this->config['telephony']['extensions'][$rawNumber]) ? $rawNumber : Phone::normalize($rawNumber);
        if ($number === $client['extension']) throw new \InvalidArgumentException('Die eigene Nebenstelle kann nicht angerufen werden.');
        $id = self::requestId($input['request_id'] ?? '');
        $signature = $clientId . ':' . $number;
        if (isset($this->outgoing[$id])) {
            if ($this->outgoing[$id]['_signature'] !== $signature) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
            return resolve($this->publicOutgoing($this->outgoing[$id]));
        }
        if (!$this->demo && (!$this->ami?->connected() || !$this->state->ready())) throw new \RuntimeException('Keine aktuellen AMI-Telefoniedaten. Bitte kurz warten.');
        if ($this->extensionBusy($client['extension'])) throw new \RuntimeException('Für diese Nebenstelle ist bereits ein Gespräch oder Auftrag aktiv. Bei ungewissem Ergebnis zuerst das Telefon prüfen.');
        if (count($this->outgoing) >= 100) throw new \RuntimeException('Zu viele Telefonieaufträge. Bitte kurz warten.');
        $this->outgoing[$id] = ['request_id'=>$id,'client_id'=>$clientId,'extension'=>$client['extension'],'number'=>$number,'phase'=>'requested','created_at'=>gmdate('c'),'_created'=>microtime(true),'_signature'=>$signature,'_source'=>'kf-' . $id,'_target'=>'','_legs'=>[],'_answered'=>false,'_ever_connected'=>false,'_hidden'=>false,'_expires'=>microtime(true) + 600];
        if ($this->demo) { $this->outgoing[$id]['phase'] = 'demo'; return resolve($this->publicOutgoing($this->outgoing[$id])); }
        // The external click-to-dial context intentionally rejects internal targets.
        // Only configured numeric extensions may use the direct internal Dial route.
        $route = isset($this->config['telephony']['extensions'][$number])
            ? ['Application'=>'Dial','Data'=>'PJSIP/' . $number . ',' . (int)ceil($this->config['telephony']['originate_timeout_ms'] / 1000) . ',f(' . $client['extension'] . ')']
            : ['Context'=>$this->config['telephony']['originate_context'], 'Exten'=>$number, 'Priority'=>'1'];
        return $this->ami->action('Originate', [
            'Channel'=>'PJSIP/' . $client['extension'], 'ChannelId'=>'kf-' . $id,
            'Async'=>'true', 'EarlyMedia'=>'false',
            'Timeout'=>$this->config['telephony']['originate_timeout_ms'], 'CallerID'=>$this->dialCallerId($number),
            'Variable'=>['__KF_C2D_ID=' . $id, '__KF_C2D_CLIENT=' . $clientId, '__KF_C2D_EXTENSION=' . $client['extension']],
        ] + $route, null, 'kf-c2d-' . $id)->then(function () use ($id) {
            if ($this->outgoing[$id]['phase'] === 'requested') $this->outgoing[$id]['phase'] = 'ringing_extension';
            return $this->publicOutgoing($this->outgoing[$id]);
        }, function () use ($id) {
            // A lost acknowledgement cannot prove that Originate was not executed.
            $this->outgoing[$id]['phase'] = 'unknown';
            return $this->publicOutgoing($this->outgoing[$id]);
        });
    }
    private function dialCallerId(string $number): string
    {
        // Look up the dialled number, never a patient number or a workflow label.
        // Internal destinations must not accidentally match a patient's local number.
        $name = '';
        if ($this->directory && !isset($this->config['telephony']['extensions'][$number])) {
            try {
                $result = $this->directory->lookup($number);
                if ($result['status'] === 'resolved') $name = $result['name'];
            } catch (\Throwable) { /* An unavailable directory must not block dialing. */ }
        }
        // Keep the AMI CallerID grammar intact, even for quotes in patient names.
        $name = trim(str_replace(['"', '<', '>', '\\'], ' ', $name));
        return '"' . $name . '" <' . $number . '>';
    }
    private function onEvent(array $e): void
    {
        $this->transfers->event($e);
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
            if (self::outgoingFinished($call)) $call['_expires'] = microtime(true) + 600;
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
                    $call['patients'] = Patients::publicPatients($lookup['result']['patients']);
                    $call['name'] = $lookup['result']['name'];
                }
            }
        }
        unset($call);
        foreach ($view['calls'] as &$call) {
            $call['transfer_extensions'] = [];
            if ($call['state'] !== 'connected') continue;
            foreach ($call['extensions'] as $extension) {
                if ($this->transfers->busy($extension)) continue;
                if (!$this->demo) {
                    try { $this->state->transferPair($call['id'],$extension); } catch (\RuntimeException) { continue; }
                }
                $call['transfer_extensions'][] = $extension;
            }
        }
        unset($call);
        $view['outgoing'] = array_values(array_map($this->publicOutgoing(...), array_filter($this->outgoing, fn($call) => !($call['_hidden'] ?? false))));
        $view['transfers'] = $this->transfers->view();
        $view['patient_directory'] = $this->directoryStatus();
        return $view;
    }
    public function transfer(array $input): PromiseInterface { return $this->transfers->start($input); }
    public function transferControl(string $id, string $action, array $input): PromiseInterface { return $this->transfers->control($id,$action,$input); }
    private static function outgoingFinished(array $call): bool { return in_array($call['phase'], ['ended','failed','demo'], true); }
    private function outgoingHasChannels(array $call): bool
    {
        foreach (array_merge([$call['_source'], $call['_target']], $call['_legs']) as $id) if ($id !== '' && $this->state->channel($id) !== null) return true;
        return false;
    }
    private function extensionBusy(string $extension): bool
    {
        foreach ($this->outgoing as $call) if ($call['extension'] === $extension && !self::outgoingFinished($call)) return true;
        if (!$this->demo) foreach ($this->state->view()['calls'] as $call) if (in_array($extension, $call['extensions'], true)) return true;
        return false;
    }
    private function outgoingCleanable(array $call): bool
    {
        return self::outgoingFinished($call) && ($this->demo || ($this->state->ready() && !$this->outgoingHasChannels($call)));
    }
    private function publicOutgoing(array $call): array
    {
        $result = array_filter($call, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
        $result['can_delete'] = !($call['_hidden'] ?? false);
        $result['can_cleanup'] = $result['can_delete'] && $this->outgoingCleanable($call);
        $result['can_retry'] = $result['can_cleanup'] && !isset($call['_retry_id']) && ($this->demo || $this->ami?->connected()) && !$this->extensionBusy($call['extension']);
        if (isset($call['_retry_id'])) $result['retried_as'] = $call['_retry_id'];
        return $result;
    }
    public function dismissCall(string $id, array $input): array
    {
        $id = self::requestId($id); $clientId = $input['client_id'] ?? ''; $this->client($clientId);
        if (!isset($this->outgoing[$id])) return ['removed'=>[$id]];
        if ($this->outgoing[$id]['client_id'] !== $clientId) throw new \InvalidArgumentException('Auftrag gehört zu einem anderen Arbeitsplatz.');
        // Only hide history. Preserve AMI tracking, active-call guards and deduplication.
        $this->outgoing[$id]['_hidden'] = true;
        return ['removed'=>[$id]];
    }
    public function cleanupCalls(array $input): array
    {
        $clientId = $input['client_id'] ?? ''; $this->client($clientId); $removed = [];
        foreach ($this->outgoing as $id => &$call) {
            if ($call['client_id'] !== $clientId || ($call['_hidden'] ?? false) || !$this->outgoingCleanable($call)) continue;
            $call['_hidden'] = true; $removed[] = $id;
        }
        unset($call);
        return ['removed'=>$removed];
    }
    public function retryCall(string $id, array $input): PromiseInterface
    {
        $id = self::requestId($id); $request = self::requestId($input['request_id'] ?? '');
        $clientId = $input['client_id'] ?? ''; $this->client($clientId);
        $call = $this->outgoing[$id] ?? null;
        if (!$call || $call['client_id'] !== $clientId) throw new \InvalidArgumentException('Rückrufauftrag für diesen Arbeitsplatz nicht gefunden.');
        if (isset($call['_retry_id'])) {
            if ($call['_retry_id'] === $request && isset($this->outgoing[$request])) return resolve($this->publicOutgoing($this->outgoing[$request]));
            throw new \RuntimeException('Dieser Auftrag wurde bereits erneut versucht. Bitte den neuen Eintrag verwenden.');
        }
        if (isset($this->outgoing[$request])) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
        if (!$this->publicOutgoing($call)['can_retry']) throw new \RuntimeException('Erneuter Versuch derzeit nicht möglich. Bitte Gesprächsstatus und Nebenstelle prüfen.');
        $result = $this->originate(['client_id'=>$clientId,'extension'=>$call['extension'],'number'=>$call['number'],'request_id'=>$request]);
        $this->outgoing[$id]['_retry_id'] = $request;
        return $result;
    }
    private function reconcileOutgoing(float $snapshotStarted): void
    {
        if (!$this->state->ready()) return;
        foreach ($this->outgoing as &$call) {
            if (self::outgoingFinished($call) || $this->outgoingHasChannels($call)) continue;
            // A snapshot during an outstanding Originate cannot prove that no channel will appear.
            if ($call['_created'] + $this->config['telephony']['originate_timeout_ms'] / 1000 + 5 > $snapshotStarted) continue;
            $call['phase'] = $call['_ever_connected'] ? 'ended' : 'failed';
            $call['_expires'] = microtime(true) + 600;
        }
        unset($call);
    }
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
        foreach ($this->outgoing as $id => $entry) if ($entry['_expires'] <= $now && self::outgoingFinished($entry)) unset($this->outgoing[$id]);
    }
    private function demoLookup(string $number): array
    {
        $one = ['patient_number'=>90001,'first_name'=>'Clara','last_name'=>'Sommer','birth_date'=>'19840412','address'=>'Beispielstraße 12, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-1'],'revision'=>1]];
        $two = ['patient_number'=>90002,'first_name'=>'Paul','last_name'=>'Becker','birth_date'=>'19770803','address'=>'Musterweg 5, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-2'],'revision'=>1]];
        $three = ['patient_number'=>90003,'first_name'=>'Anna','last_name'=>'Becker','birth_date'=>'19810319','address'=>'Musterweg 5, Musterstadt','_ref'=>['objectId'=>['id'=>'demo-3'],'revision'=>1]];
        if (str_ends_with($number, '000')) return ['status'=>'not_found','name'=>'','patients'=>[]];
        $patients = str_ends_with($number, '22') ? [$two,$three] : [$one];
        return ['status'=>count($patients) === 1 ? 'resolved' : 'ambiguous','name'=>count($patients) === 1 ? Patients::displayName($one) : '', 'patients'=>$patients];
    }
    private function demoSearch(string $query): array
    {
        $people = [...$this->demoLookup('03055501011')['patients'], ...$this->demoLookup('03055501022')['patients']];
        $terms = preg_split('/[\s,]+/u', Patients::searchKey($query), -1, PREG_SPLIT_NO_EMPTY);
        $patients = [];
        foreach ($people as $p) {
            $name = Patients::searchKey($p['first_name'] . ' ' . $p['last_name']);
            if ((ctype_digit($query) && (int)$query === $p['patient_number']) || (preg_match('/\p{L}/u', $query) && !array_filter($terms, static fn($t) => !str_contains($name,$t)))) $patients[$p['patient_number']] = $p;
        }
        if (Phone::external($query) !== '') foreach ($this->demoLookup(Phone::normalize($query))['patients'] as $p) $patients[$p['patient_number']] = $p;
        $patients = array_values($patients);
        return ['status'=>!$patients ? 'not_found' : (count($patients) === 1 ? 'resolved' : 'ambiguous'),'name'=>'','patients'=>$patients,'truncated'=>false];
    }
    private function demoView(): array
    {
        $calls = [];
        foreach ([['demo-1','03055501011','waiting',[],74],['demo-2','03055501022','waiting',[],31],['demo-3','03055501033','connected',['201'],143]] as [$id,$number,$state,$extensions,$duration]) {
            $this->lookup($number)->then(static fn() => null);
            $key = Phone::key($number); $lookupId = $this->phoneLookups[$key]; $result = $this->lookups[$lookupId]['result'];
            $calls[] = ['id'=>$id,'number'=>$number,'direction'=>'incoming','state'=>$state,'extensions'=>$extensions,'channel_age_seconds'=>$duration,'duration_seconds'=>$state === 'connected' ? $duration : null,'name_status'=>$result['status'],'name'=>$result['name'],'lookup_id'=>$lookupId,'patients'=>Patients::publicPatients($result['patients'])];
        }
        return ['generated_at'=>gmdate('c'),'ami_connected'=>true,'stale'=>false,'calls'=>$calls,'queues'=>[['name'=>'praxis','waiting_count'=>2,'entries'=>[['call_id'=>'demo-1','position'=>1,'wait_seconds'=>74],['call_id'=>'demo-2','position'=>2,'wait_seconds'=>31]]]],'extensions'=>[['id'=>'201','label'=>'Empfang 1','state'=>'busy','paused'=>false],['id'=>'202','label'=>'Empfang 2','state'=>'idle','paused'=>false]]];
    }
}
