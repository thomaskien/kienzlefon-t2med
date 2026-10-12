<?php
declare(strict_types=1);
namespace Kienzlefon;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/** Transfer commands are bound to a staff channel, never to a browser-supplied AMI channel. */
final class Transfers
{
    private array $jobs = [];
    private array $controls = [];
    private const TERMINAL = ['transferred','cancelled','failed'];

    public function __construct(private Telephony $state, private \Closure $send, private array $config, private bool $demo = false) {}

    public function start(array $input): PromiseInterface
    {
        $clientId = $input['client_id'] ?? '';
        $client = $this->client($clientId);
        $id = self::id($input['request_id'] ?? null);
        $callId = $input['call_id'] ?? null;
        $mode = $input['mode'] ?? null; $type = $input['target_type'] ?? null;
        if (!is_string($callId) || $callId === '' || strlen($callId) > 256 || !in_array($mode, ['blind','attended'], true) || !in_array($type, ['internal','external'], true)) throw new \InvalidArgumentException('Ungültiger Vermittlungsauftrag.');
        $target = $input['target'] ?? '';
        if (!is_string($target)) throw new \InvalidArgumentException('Ungültiges Vermittlungsziel.');
        if ($type === 'internal') {
            if (!isset($this->config['telephony']['extensions'][$target]) || $target === $client['extension']) throw new \InvalidArgumentException('Bitte eine andere konfigurierte Nebenstelle wählen.');
        } else {
            $target = Phone::normalize($target);
            if (Phone::external($target, $this->config['telephony']['extensions']) === '') throw new \InvalidArgumentException('Bitte eine vollständige externe Zielrufnummer eingeben.');
            $target = Phone::key($target, $this->config['telephony']['country_code']);
            if (str_starts_with($target, '+')) $target = '00' . substr($target, 1);
        }
        if (!preg_match('/^[0-9]{1,32}$/D', $target)) throw new \InvalidArgumentException('Ungültiges Vermittlungsziel.');
        $signature = json_encode([$clientId,$callId,$mode,$type,$target]);
        if (isset($this->jobs[$id])) {
            if ($this->jobs[$id]['_signature'] !== $signature) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
            return resolve($this->publicJob($this->jobs[$id]));
        }
        $this->collect();
        if (count($this->jobs) >= 128) throw new \RuntimeException('Zu viele Vermittlungsaufträge.');
        if ($this->demo) {
            if ($callId !== 'demo-3' || $client['extension'] !== '201') throw new \RuntimeException('Dieses Gespräch gehört nicht zum eigenen Arbeitsplatz.');
            $pair = ['source_id'=>'demo-source','source_channel'=>'demo','peer_id'=>'demo-peer','peer_channel'=>'demo'];
        } else $pair = $this->state->transferPair($callId, $client['extension']);
        foreach ($this->jobs as $job) if (!in_array($job['phase'], self::TERMINAL, true)
            && array_intersect([$job['_pair']['source_id'],$job['_pair']['peer_id']],[$pair['source_id'],$pair['peer_id']])) throw new \RuntimeException('Für dieses Gespräch läuft bereits ein Vermittlungsauftrag. Bei ungewissem Ergebnis zuerst das Telefon prüfen.');
        $this->jobs[$id] = ['request_id'=>$id,'client_id'=>$clientId,'call_id'=>$callId,'extension'=>$client['extension'],'target_type'=>$type,'target'=>$target,'mode'=>$mode,
            'phase'=>'preparing','message'=>'Vermittlung wird vorbereitet.','created_at'=>gmdate('c'),
            '_signature'=>$signature,'_pair'=>$pair,'_context'=>$this->config['telephony']['transfer_context'],
            '_sent'=>false,'_left'=>false,'_route'=>false,'_legs'=>[],'_target'=>'','_deadline'=>microtime(true)+8,'_updated'=>microtime(true)];
        if ($this->demo) {
            $this->phase($id, $mode === 'attended' ? 'consulting' : 'transferred', $mode === 'attended' ? 'Demo: Rückfrage verbunden.' : 'Demo: Vermittlung simuliert.');
            return resolve($this->publicJob($this->jobs[$id]));
        }
        $checks = [
            'ready'=>$this->get($pair['source_channel'], 'KF_TRANSFER_READY'),
            'route'=>$this->get($pair['source_channel'], 'DIALPLAN_EXISTS(' . $this->jobs[$id]['_context'] . ',' . $target . ',1)'),
        ];
        if ($mode === 'attended') foreach (['start'=>'FEATUREMAP(atxfer)','abort'=>'FEATURE(atxferabort)','complete'=>'FEATURE(atxfercomplete)'] as $key=>$function) $checks[$key] = $this->get($pair['source_channel'], $function);
        return all($checks)->then(function ($values) use ($id, $mode, $pair, $clientId, $client, $type, $target) {
            if ($values['ready'] !== '1' || $values['route'] !== '1') throw new \RuntimeException('Vermitteln ist am Telefonserver noch nicht eingerichtet. Kienzlefon-Installer gemäß Transfer-Übergabe aktualisieren.');
            if ($mode === 'attended') foreach (['start','abort','complete'] as $key) if (!self::featureCode($values[$key])) throw new \RuntimeException('Rückfrage-Funktionen am Telefonserver sind noch nicht eingerichtet.');
            $vars = ['__KF_TRANSFER_ID'=>$id,'__KF_TRANSFER_CLIENT'=>$clientId,'__KF_TRANSFER_EXTENSION'=>$client['extension'],
                '__KF_TRANSFER_SOURCE_UID'=>$pair['source_id'],'__KF_TRANSFER_MODE'=>$mode,'__KF_TRANSFER_TARGET_TYPE'=>$type,'__KF_TRANSFER_TARGET'=>$target];
            $chain = resolve(null);
            foreach (array_unique([$pair['source_channel'],$pair['peer_channel'],$pair['transferee_channel']]) as $channel) foreach ($vars as $key=>$value) $chain = $chain->then(function () use ($id,$channel,$key,$value) {
                $this->guardOriginal($id);
                return ($this->send)('Setvar', ['Channel'=>$channel,'Variable'=>$key,'Value'=>$value]);
            });
            return $chain;
        })->then(function () use ($id,$mode,$target) {
            $this->guardOriginal($id);
            $this->jobs[$id]['_sent'] = true;
            $this->phase($id,'requested',$mode === 'attended' ? 'Rückfrage angefordert; Ziel wird angerufen.' : 'Weitergabe angefordert.');
            return ($this->send)($mode === 'attended' ? 'Atxfer' : 'BlindTransfer', ['Channel'=>$this->jobs[$id]['_pair']['source_channel'],'Context'=>$this->jobs[$id]['_context'],'Exten'=>$target]);
        })->then(function () use ($id,$mode) {
            if ($mode === 'blind' && in_array($this->jobs[$id]['phase'], ['requested','ringing'], true)) $this->phase($id,'transferred','Weitergabe an das Ziel gestartet; Zielannahme ist noch nicht bestätigt.');
            if ($mode === 'attended' && $this->jobs[$id]['phase'] === 'requested') $this->watch($id);
            return $this->publicJob($this->jobs[$id]);
        }, function (\Throwable $e) use ($id) {
            if (!in_array($this->jobs[$id]['phase'], self::TERMINAL, true)) {
                $uncertain = $this->jobs[$id]['_sent'] && $e->getCode() !== 403;
                $this->phase($id,$uncertain ? 'unknown' : 'failed',$uncertain ? 'Ergebnis ungewiss. Telefon prüfen; Auftrag nicht erneut starten.' : $e->getMessage());
            }
            return $this->publicJob($this->jobs[$id]);
        });
    }

    public function control(string $id, string $action, array $input): PromiseInterface
    {
        $id = self::id($id); $requestId = self::id($input['request_id'] ?? null);
        $clientId = $input['client_id'] ?? ''; $this->client($clientId);
        if (!isset($this->jobs[$id]) || $this->jobs[$id]['client_id'] !== $clientId || !in_array($action, ['cancel','complete'], true)) throw new \InvalidArgumentException('Vermittlung gehört nicht zum Arbeitsplatz.');
        $key = $clientId . ':' . $requestId; $signature = $id . ':' . $action;
        if (isset($this->controls[$key])) {
            if ($this->controls[$key]['signature'] !== $signature) throw new \InvalidArgumentException('Vorgangs-ID wurde bereits anders verwendet.');
            return resolve($this->publicJob($this->jobs[$id]));
        }
        if (!$this->canControl($this->jobs[$id], $action)) throw new \RuntimeException('Rückfrage ist nicht in einem passenden Zustand. Telefon prüfen.');
        if (count($this->controls) >= 512) throw new \RuntimeException('Zu viele Vermittlungsaktionen.');
        $this->controls[$key] = ['signature'=>$signature,'job'=>$id];
        if ($this->demo) {
            $this->phase($id, $action === 'complete' ? 'transferred' : 'cancelled', $action === 'complete' ? 'Demo: Vermittlung abgeschlossen.' : 'Demo: Zurück beim Anrufer.');
            return resolve($this->publicJob($this->jobs[$id]));
        }
        $phase = $action === 'complete' ? 'completing' : 'cancelling';
        $this->phase($id,$phase,$action === 'complete' ? 'Abschließen angefordert.' : 'Rückkehr zum Anrufer angefordert.');
        if ($action === 'cancel') {
            $promise = ($this->send)('CancelAtxfer', ['Channel'=>$this->sourceChannel($id)]);
        } else {
            $promise = $this->get($this->sourceChannel($id), 'FEATURE(atxfercomplete)')->then(function ($code) use ($id) {
                if (!self::featureCode($code)) throw new \RuntimeException('Abschlusscode für Rückfrage fehlt. Am Telefon abschließen.');
                $chain = resolve(null);
                foreach (str_split($code) as $digit) $chain = $chain->then(function () use ($id,$digit) {
                    if (!$this->consulting($this->jobs[$id])) throw new \RuntimeException('Rückfragezustand hat sich geändert. Telefon prüfen.');
                    return ($this->send)('PlayDTMF', ['Channel'=>$this->sourceChannel($id),'Digit'=>$digit,'Receive'=>'true','Duration'=>'100']);
                });
                return $chain;
            });
        }
        return $promise->then(function () use ($id) { $this->watch($id); return $this->publicJob($this->jobs[$id]); }, function () use ($id) {
            if (!in_array($this->jobs[$id]['phase'], self::TERMINAL, true)) $this->phase($id,'unknown','Ergebnis der Rückfrage-Aktion ungewiss. Am Telefon prüfen.');
            return $this->publicJob($this->jobs[$id]);
        });
    }

    public function event(array $e): void
    {
        foreach ($this->jobs as $id=>&$job) {
            if (!$job['_sent'] || in_array($job['phase'], self::TERMINAL, true)) continue;
            $type = $e['Event'] ?? ''; $source = $job['_pair']['source_id']; $peer = $job['_pair']['peer_id'];
            if ($type === 'UserEvent' && ($e['UserEvent'] ?? '') === 'KienzlefonTransferProgress' && ($e['SchemaVersion'] ?? '') === '1' && ($e['RequestId'] ?? '') === $id && ($e['TransfererUniqueid'] ?? '') === $source) {
                if (($e['Phase'] ?? '') === 'dialing_target' && !empty($e['SourceUniqueid'])) {
                    $job['_route'] = true;
                    if (!in_array($e['SourceUniqueid'],$job['_legs'],true)) $job['_legs'][] = $e['SourceUniqueid'];
                    if ($job['phase'] === 'requested') $this->phase($id,'ringing','Vermittlungsziel wird angerufen.');
                }
                if (($e['Phase'] ?? '') === 'failed') $this->phase($id,'failed','Vermittlungsziel konnte nicht erreicht werden. Telefon prüfen.');
            }
            if ($type === 'DialBegin' && in_array($e['Uniqueid'] ?? '', $job['_legs'], true) && !empty($e['DestUniqueid'])) {
                $job['_legs'][] = $e['DestUniqueid'];
                if (!str_starts_with($e['DestChannel'] ?? '', 'Local/')) $job['_target'] = $e['DestUniqueid'];
            }
            $transferEvent = $job['mode'] === 'attended' ? 'AttendedTransfer' : 'BlindTransfer';
            $transferSource = $job['mode'] === 'attended' ? ($e['OrigTransfererUniqueid'] ?? '') : ($e['TransfererUniqueid'] ?? '');
            if ($type === $transferEvent && $transferSource === $source && in_array($e['TransfereeUniqueid'] ?? '', [$peer,$job['_pair']['transferee_id']], true)) {
                $ok = ($e['Result'] ?? '') === 'Success' && ($e['DestType'] ?? '') !== 'Threeway';
                $this->phase($id,$ok ? 'transferred' : 'failed',$ok ? 'Vermittlung an Asterisk übergeben.' : 'Vermittlung nicht abgeschlossen. Telefon prüfen.');
                continue;
            }
            if ($job['mode'] !== 'attended' || !$this->state->ready() || in_array($job['phase'], self::TERMINAL, true)) continue;
            $together = $this->state->bridged($source,$peer);
            if (!$together && $this->state->channel($source) && $this->state->channel($peer)) $job['_left'] = true;
            if ($job['_left'] && $together) { $this->phase($id,'cancelled','Wieder mit dem ursprünglichen Anrufer verbunden.'); continue; }
            if ($this->consulting($job) && in_array($job['phase'], ['requested','ringing'], true)) $this->phase($id,'consulting','Rückfrage verbunden. Vermittlung abschließen oder zum Anrufer zurückkehren.');
            if ($type === 'Hangup' && in_array($e['Uniqueid'] ?? '', [$source,$peer,$job['_target']], true)) $this->watch($id);
        }
        unset($job);
    }

    public function disconnect(): void
    {
        foreach ($this->jobs as $id=>$job) if (!in_array($job['phase'], self::TERMINAL, true)) $this->phase($id,'unknown','Telefonieverbindung unterbrochen. Vermittlung am Telefon prüfen.');
    }
    public function view(): array { $this->collect(); return array_values(array_map($this->publicJob(...),$this->jobs)); }
    public function busy(string $extension): bool
    {
        foreach ($this->jobs as $job) if ($job['extension'] === $extension && !in_array($job['phase'],self::TERMINAL,true) && ($this->demo || !$this->state->ready() || $this->state->channel($job['_pair']['source_id']))) return true;
        return false;
    }
    private function guardOriginal(string $id): void
    {
        $job = $this->jobs[$id];
        if (microtime(true) > $job['_deadline'] || $job['phase'] !== 'preparing') throw new \RuntimeException('Vermittlungsvorbereitung abgebrochen.');
        if ($this->state->transferPair($job['call_id'],$job['extension']) !== $job['_pair']) throw new \RuntimeException('Gespräch hat sich geändert. Bitte erneut auswählen.');
    }
    private function consulting(array $job): bool
    {
        $p = $job['_pair'];
        return $job['_route'] && $job['_target'] !== '' && $this->state->ready() && $this->state->channel($p['peer_id']) !== null
            && !$this->state->bridged($p['source_id'],$p['peer_id']) && $this->state->bridged($p['source_id'],$job['_target']);
    }
    private function canControl(array $job, string $action): bool
    {
        if ($job['mode'] !== 'attended' || !in_array($job['phase'], ['ringing','consulting'], true)) return false;
        if ($this->demo) return true;
        $p = $job['_pair'];
        if (!$this->state->ready() || !$job['_route'] || !$job['_left'] || !$this->state->channel($p['source_id']) || !$this->state->channel($p['peer_id']) || $this->state->bridged($p['source_id'],$p['peer_id'])) return false;
        return $action === 'cancel' || $this->consulting($job);
    }
    private function sourceChannel(string $id): string
    {
        return $this->state->channel($this->jobs[$id]['_pair']['source_id'])['Channel'] ?? throw new \RuntimeException('Eigener Gesprächskanal nicht mehr verfügbar.');
    }
    private function get(string $channel, string $variable): PromiseInterface { return ($this->send)('Getvar',['Channel'=>$channel,'Variable'=>$variable])->then(static fn($reply) => (string)($reply['Value'] ?? '')); }
    private static function featureCode(string $code): bool { return (bool)preg_match('/^[0-9*#]{1,8}$/D',$code); }
    private function phase(string $id, string $phase, string $message): void { $this->jobs[$id]['phase']=$phase; $this->jobs[$id]['message']=$message; $this->jobs[$id]['_updated']=microtime(true); }
    private function watch(string $id): void
    {
        $phase = $this->jobs[$id]['phase'];
        Loop::addTimer(15, function () use ($id,$phase) {
            if (isset($this->jobs[$id]) && $this->jobs[$id]['phase'] === $phase && !in_array($phase,self::TERMINAL,true)) $this->phase($id,'unknown','Keine eindeutige Rückmeldung. Vermittlung am Telefon prüfen.');
        });
    }
    private function publicJob(array $job): array
    {
        $result = array_filter($job,static fn($key) => !str_starts_with($key,'_'), ARRAY_FILTER_USE_KEY);
        $result['can_cancel']=$this->canControl($job,'cancel'); $result['can_complete']=$this->canControl($job,'complete');
        return $result;
    }
    private function collect(): void
    {
        if (!$this->demo && $this->state->ready()) foreach ($this->jobs as $id=>$job) {
            if ($job['phase'] === 'unknown' && !$this->state->channel($job['_pair']['source_id']) && !$this->state->channel($job['_pair']['peer_id'])) $this->phase($id,'failed','Gespräch beendet; Vermittlungsergebnis nicht bestätigt.');
        }
        foreach ($this->jobs as $id=>$job) if (microtime(true)-$job['_updated'] > 600 && in_array($job['phase'],self::TERMINAL,true)) unset($this->jobs[$id]);
        foreach ($this->controls as $key=>$control) if (!isset($this->jobs[$control['job']])) unset($this->controls[$key]);
    }
    private function client(mixed $id): array
    {
        if (!is_string($id) || !isset($this->config['clients'][$id])) throw new \InvalidArgumentException('Bitte einen registrierten Arbeitsplatz wählen.');
        return $this->config['clients'][$id];
    }
    private static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$id)) throw new \InvalidArgumentException('Ungültige Vorgangs-ID.');
        return strtolower($id);
    }
}
