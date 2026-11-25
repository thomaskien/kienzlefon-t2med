<?php
declare(strict_types=1);
namespace Kienzlefon;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class TelephonyControl
{
    private ?RoutingJournal $journal=null;
    private \Closure $send;
    private \Closure $sendRouting;
    private \Closure $wall;
    private \Closure $mono;
    private ?array $caps=null;
    private ?array $snapshot=null;
    private string $failure='not_configured';
    private string $writeFailure='state_unavailable';
    private float $received=0;
    private float $roundtrip=0;
    private bool $refreshing=false;
    private array $polling=[];
    private array $lastPoll=[];
    private array $timers=[];
    private const EPOCH='de000000-0000-4000-8000-000000000001';
    public function __construct(private array $config, private bool $demo=false, ?callable $transport=null, ?callable $wall=null, ?callable $mono=null)
    {
        $this->wall=\Closure::fromCallable($wall ?? static fn()=>microtime(true));
        $this->mono=\Closure::fromCallable($mono ?? static fn()=>hrtime(true)/1e9);
        $this->send=\Closure::fromCallable($transport ?? (new ControlTransport($config['telephony_control']))->request(...));
        $routing = $config['telephony_control'];
        if ($this->paired()) $routing['token_file'] = $routing['routing_token_file'];
        $this->sendRouting = \Closure::fromCallable($transport ?? (new ControlTransport($routing))->request(...));
        if ($demo) $this->journal=new RoutingJournal(':memory:');
        if ($this->enabled()) $this->failure='source_disconnected';
    }
    private function enabled(): bool { return $this->config['telephony_control']['enabled']; }
    private function paired(): bool
    {
        return ($this->config['telephony_control']['routing_token_file'] ?? '') !== '';
    }

    /** The App credential is bound to this PBX. Fetch policy again for each authorization. */
    public function workplaces(): PromiseInterface
    {
        if (!$this->paired() || !$this->enabled() || $this->demo) throw new ControlError('not_supported');
        return ($this->sendRouting)('GET', '/control/v1/routing/workplaces', null)->then(function ($response) {
            $status = $response['status'];
            if ($status !== 200) throw new ControlError(match ($status) {
                401, 403 => 'upstream_authentication_failed', 404, 501 => 'state_unavailable', default => 'upstream_unreachable'
            });
            $scope = $response['body'];
            $ids = $scope['client_ids'] ?? null;
            $mode = $scope['mode'] ?? null;
            if (($scope['schema_version'] ?? null) !== 1 || ($scope['pbx_id'] ?? null) !== $this->config['telephony_control']['pbx_id']
                || !is_bool($scope['remote_enabled'] ?? null) || !in_array($mode, ['all','selected'], true)
                || !is_array($ids) || !array_is_list($ids) || ($mode === 'all' && $ids !== [])) {
                throw new ControlError('state_unavailable');
            }
            foreach ($ids as $id) if (!TelephonyContract::valid('ClientId', $id)) throw new ControlError('state_unavailable');
            if (count($ids) !== count(array_unique($ids))) throw new ControlError('state_unavailable');
            $app = $this->config['app_access'];
            if ($app['allowed_clients'] !== [] || $app['workplace_mode'] === 'selected') {
                $ids = $mode === 'all' ? $app['allowed_clients'] : array_values(array_intersect($ids, $app['allowed_clients']));
                $mode = 'selected';
            }
            return ['schema_version'=>1, 'pbx_id'=>$scope['pbx_id'], 'remote_enabled'=>$scope['remote_enabled'],
                'mode'=>$mode, 'client_ids'=>$ids];
        });
    }

    private function commandTransport(?string $pairedPbx): \Closure
    {
        return $pairedPbx !== null || ($this->config['telephony_control']['legacy_routing'] ?? false)
            ? $this->sendRouting : $this->send;
    }

    private function journal(): RoutingJournal
    {
        try { return $this->journal ??= new RoutingJournal($this->config['telephony_control']['request_database']); }
        catch (\Throwable) { throw new ControlError('state_unavailable'); }
    }
    private function now(): float { return ($this->wall)(); }
    private function tick(): float { return ($this->mono)(); }
    private function stamp(): string { return gmdate('Y-m-d\TH:i:s\Z',(int)$this->now()); }
    public function start(): void
    {
        if ($this->demo || !$this->enabled()) return;
        $this->refresh();
        $this->timers[]=Loop::addPeriodicTimer(2,fn()=>$this->refresh());
        $this->timers[]=Loop::addPeriodicTimer(2,fn()=>$this->poll());
        $this->timers[]=Loop::addPeriodicTimer(3600,function(){try{$this->journal()->prune($this->now());}catch(\Throwable){}});
    }
    public function aiEnabled(): bool { return $this->demo || ($this->snapshot['ai_enabled'] ?? false); }
    public function connected(): bool { return $this->caps!==null && $this->snapshot!==null; }
    public function stop(): void { foreach($this->timers as $timer) Loop::cancelTimer($timer); $this->timers=[]; }
    public function refresh(): PromiseInterface
    {
        if (!$this->enabled() || $this->demo || $this->refreshing) return resolve(null);
        $this->refreshing=true; $start=$this->tick();
        $cap=$this->caps===null ? ($this->send)('GET','/control/v1/capabilities',null)->then(function($r){
            if ($r['status']!==200) throw new ControlError(in_array($r['status'],[401,403],true)?'upstream_authentication_failed':'upstream_unreachable');
            if (!is_array($r['body']) || !TelephonyContract::valid('ControlCapabilities',$r['body'],true) || $r['body']['pbx_id']!==$this->config['telephony_control']['pbx_id']) throw new ControlError('state_unavailable');
            $this->caps=TelephonyContract::project('ControlCapabilities',$r['body']);
        }) : resolve(null);
        return $cap->then(fn()=>($this->send)('GET','/control/v1/state',null))->then(function($r) use($start){
            if ($r['status']!==200) throw new ControlError(in_array($r['status'],[401,403],true)?'upstream_authentication_failed':'upstream_unreachable');
            $s=$r['body'];
            if (!is_array($s) || ($s['schema_version']??null)!==1 || ($s['pbx_id']??null)!==$this->config['telephony_control']['pbx_id'] || !array_key_exists('pbx_epoch',$s) || ($s['pbx_epoch']!==null&&!TelephonyContract::valid('UUID',$s['pbx_epoch'])) || TelephonyContract::timestamp($s['generated_at']??null)===null) throw new ControlError('state_unavailable');
            // Validate independently: a broken routing source must not hide valid counters.
            $l=is_array($s['live_counters']??null)&&TelephonyContract::valid('LiveCounters',$s['live_counters'],true);
            $r=is_array($s['routing_control']??null)&&TelephonyContract::valid('RoutingControl',$s['routing_control'],true);
            $ext=is_array($s['extensions']??null)&&array_is_list($s['extensions'])&&count($s['extensions'])<=1000;
            if ($ext) foreach($s['extensions'] as $e) if (!TelephonyContract::valid('ControlExtension',$e,true)) {$ext=false;break;}
            if ($r&&$ext) $r=TelephonyContract::routingSemantics($s['routing_control'],$s['extensions']); else $r=false;
            if (($l&&$s['live_counters']['status']==='current'&&$s['live_counters']['pbx_epoch']!==$s['pbx_epoch']) || ($r&&$s['routing_control']['available']&&$s['routing_control']['pbx_epoch']!==$s['pbx_epoch'])) throw new ControlError('state_unavailable');
            $this->snapshot=['ai_enabled'=>($s['ai_enabled'] ?? false) === true,'live'=>$l?TelephonyContract::project('LiveCounters',$s['live_counters']):null,'routing'=>$r?TelephonyContract::project('RoutingControl',$s['routing_control']):null,'extensions'=>$r?array_map(fn($e)=>TelephonyContract::project('ControlExtension',$e),$s['extensions']):[]];
            $this->received=$this->tick(); $this->roundtrip=max(0,($this->received-$start)*1000); $this->failure='source_incomplete'; $this->writeFailure='state_unavailable';
        })->then(function(){$this->refreshing=false;},function(\Throwable $e){
            $this->writeFailure=$e instanceof ControlError && in_array($e->reason,['upstream_timeout','upstream_unreachable','upstream_authentication_failed'],true)?$e->reason:'state_unavailable';
            $this->snapshot=null; $this->failure=$e instanceof ControlError&&$e->reason==='state_unavailable'?'source_incomplete':'source_disconnected'; $this->refreshing=false;
        });
    }
    public static function unknownRouting(): array
    {
        $p=['can_enable'=>false,'can_disable'=>false,'enable_reason'=>'state_unknown','disable_reason'=>'state_unknown'];
        return ['normal'=>null,'specialist'=>null,'permissions'=>['normal'=>$p,'specialist'=>$p]];
    }
    private function unavailableRouting(string $reason): array
    {
        return ['supported'=>$this->caps['features']['routing_control']??false,'enabled'=>null,'available'=>false,'pbx_epoch'=>null,'revision'=>null,'as_of'=>null,'age_ms'=>null,'max_age_ms'=>10000,'reason_code'=>$reason,'groups'=>[['id'=>'normal','label'=>'Normal','ivr_digit'=>null,'available'=>false],['id'=>'specialist','label'=>'ROT','ivr_digit'=>'9','available'=>false]]];
    }
    private function unavailableLive(string $reason): array
    {
        return ['status'=>'unavailable','reason_code'=>$reason,'pbx_epoch'=>null,'as_of'=>null,'age_ms'=>null,'max_age_ms'=>10000,'waiting'=>null,'ivr'=>null,'ai'=>null];
    }
    private function age(array $v): int
    {
        // Preserve the producer's age; add transport and monotonic local residence.
        return (int)ceil(max($v['age_ms']+$this->roundtrip+max(0,($this->tick()-$this->received)*1000),max(0,($this->now()-(TelephonyContract::timestamp($v['as_of'])??0))*1000)));
    }
    public function view(array $localExtensions, ?float $stateAt, array $statistics): array
    {
        if($this->demo && $this->snapshot===null) $this->demoState($localExtensions);
        $live=$this->snapshot['live']??$this->unavailableLive($this->failure);
        $routing=$this->snapshot['routing']??$this->unavailableRouting($this->failure);
        if (!$this->demo && $this->caps!==null) {
            if (!$this->caps['features']['live_counters']) $live=$this->unavailableLive('not_supported');
            if (!$this->caps['features']['routing_control']) $routing=$this->unavailableRouting('not_supported');
        }
        if ($this->demo) { $live['as_of']=$routing['as_of']=$this->stamp();$live['age_ms']=$routing['age_ms']=0;$this->received=$this->tick(); }
        if ($live['status']==='current') { $live['age_ms']=$this->age($live); if($live['age_ms']>=10000)$live=$this->unavailableLive('stale'); }
        if ($routing['available']) { $routing['age_ms']=$this->age($routing); if($routing['age_ms']>=10000)$routing=$this->unavailableRouting('stale'); }
        $byId=array_column($this->snapshot['extensions']??[],null,'id'); $local=array_column($localExtensions,null,'id');
        $ids = array_diff(array_unique(array_merge(array_keys($local), array_keys($byId))), $this->config['telephony']['inactive_extensions']);
        $extensions=[];
        $stateAge=$stateAt===null?null:(int)max(0,ceil(($this->now()-$stateAt)*1000));
        foreach($ids as $id){
            $e=$local[$id]??null; $known=$e!==null&&$stateAge!==null&&$stateAge<10000;
            $state=$known?($e['state']??'unknown'):'unknown'; if(!in_array($state,['idle','ringing','busy','offline','unknown'],true))$state='unknown';
            $extensions[]=['id'=>(string)$id,'label'=>$byId[$id]['label']??$e['label']??(string)$id,'state'=>$state,'state_as_of'=>$known?gmdate('Y-m-d\TH:i:s\Z',(int)$stateAt):null,'state_age_ms'=>$known?$stateAge:null,'routing'=>$routing['available']?($byId[$id]['routing']??self::unknownRouting()):self::unknownRouting()];
        }
        return ['schema_version'=>1,'generated_at'=>$this->stamp(),'source'=>$this->demo?'demo':'live','capabilities'=>['live_counters'=>$this->caps['features']['live_counters']??false,'routing_control'=>$this->caps['features']['routing_control']??false,'call_statistics'=>$this->demo||$this->config['call_statistics']['enabled']],'live_counters'=>$live,'routing_control'=>$routing,'extensions'=>$extensions,'call_statistics'=>$statistics];
    }
    private function demoState(array $extensions): void
    {
        $this->caps=['features'=>['live_counters'=>true,'routing_control'=>true]];
        $r=$this->unavailableRouting('not_configured'); $r=array_replace($r,['supported'=>true,'enabled'=>true,'available'=>true,'pbx_epoch'=>self::EPOCH,'revision'=>'r1:'.self::EPOCH.':1','as_of'=>$this->stamp(),'age_ms'=>0,'reason_code'=>null]);
        foreach($r['groups'] as &$g)$g['available']=true;unset($g);
        $p=['can_enable'=>true,'can_disable'=>true,'enable_reason'=>null,'disable_reason'=>null];
        $ext=array_map(fn($e)=>['id'=>$e['id'],'label'=>$e['label'],'routing'=>['normal'=>true,'specialist'=>true,'permissions'=>['normal'=>$p,'specialist'=>$p]]],$extensions);
        $this->snapshot=['live'=>array_replace($this->unavailableLive('not_configured'),['status'=>'current','reason_code'=>null,'pbx_epoch'=>self::EPOCH,'as_of'=>$this->stamp(),'age_ms'=>0,'waiting'=>2,'ivr'=>1,'ai'=>1]),'routing'=>$r,'extensions'=>$ext]; $this->received=$this->tick();
        $this->demoPermissions();
    }
    private function demoPermissions(): void
    {
        foreach(['normal','specialist'] as $g){$n=count(array_filter($this->snapshot['extensions'],fn($e)=>$e['routing'][$g]));
            foreach($this->snapshot['extensions'] as &$e){$last=$e['routing'][$g]&&$n===1;$e['routing']['permissions'][$g]['can_disable']=!$last;$e['routing']['permissions'][$g]['disable_reason']=$last?'last_member':null;}unset($e);
        }
    }
    public function get(string $id, ?array $clients=null, ?string $pairedPbx=null): array
    {
        if (!TelephonyContract::valid('UUID',$id)) throw new ControlError('invalid_request');
        $row=$this->journal()->get($id); if(!$row || $row['paired_pbx'] !== $pairedPbx)throw new ControlError('request_not_found');
        if($clients!==null&&!in_array($row['command']['client_id'],$clients,true))throw new ControlError('client_forbidden');
        return ['request'=>$row['job'],'error'=>$row['job']['error']];
    }
    public function submit(array $input,?array $clients=null,?string $pairedPbx=null): PromiseInterface
    {
        $c=TelephonyContract::command($input);
        if($pairedPbx === null && !isset($this->config['clients'][$c['client_id']]))throw new ControlError('unknown_client');
        if($clients!==null&&!in_array($c['client_id'],$clients,true))throw new ControlError('client_forbidden');
        if(!$this->enabled()&&!$this->demo)throw new ControlError('not_supported');
        $j=$this->journal();$old=$j->get($c['request_id']);
        if ($old) {
            $previous = $this->get($c['request_id'], $clients, $pairedPbx);
            if ($old['command'] !== $c) throw new ControlError('request_id_conflict');
            return resolve($previous);
        }
        $expiry=TelephonyContract::timestamp($c['expires_at']);
        if($expiry<=$this->now())throw new ControlError('request_expired');
        if($expiry>$this->now()+35)throw new ControlError('invalid_request');
        $v=$this->view([],null,[]);$r=$v['routing_control'];
        if(!$r['available'])throw new ControlError($this->writeFailure);
        $fail=fn($reason)=>new ControlError($reason,$r['revision'],$r['pbx_epoch']);
        if($r['pbx_epoch']!==$c['pbx_epoch'])throw $fail('pbx_restarted');
        if($r['revision']!==$c['expected_revision'])throw $fail('revision_conflict');
        if(!$r['enabled'])throw $fail('global_disabled');
        $e=array_column($v['extensions'],null,'id')[$c['extension']]??null;if(!$e)throw $fail('unknown_extension');
        $p=$e['routing']['permissions'][$c['route']];$direction=$c['member']?'enable':'disable';
        if(!$p['can_'.$direction])throw $fail($p[$direction.'_reason']);
        if(!$c['member']&&$e['routing'][$c['route']]===true&&count(array_filter($this->snapshot['extensions'],fn($x)=>$x['routing'][$c['route']]===true))<=1)throw $fail('last_member');
        $job=$c+['state'=>'unknown','created_at'=>$this->stamp(),'updated_at'=>$this->stamp(),'applied_at'=>null,'result_revision'=>null,'changed'=>null,'error'=>(new ControlError('state_unknown'))->payload()];
        $j->insert($c,$job,$this->now(),$pairedPbx); // Durable before the first possible byte of POST.
        if($this->demo){
            $changed=false;foreach($this->snapshot['extensions'] as &$ex)if($ex['id']===$c['extension']){$changed=$ex['routing'][$c['route']]!==$c['member'];$ex['routing'][$c['route']]=$c['member'];}unset($ex);
            $counter=(int)explode(':',$r['revision'])[2]+(int)$changed;$this->snapshot['routing']['revision']='r1:'.self::EPOCH.':'.$counter;$this->demoPermissions();
            $job=array_replace($job,['state'=>'applied','applied_at'=>$this->stamp(),'result_revision'=>$this->snapshot['routing']['revision'],'changed'=>$changed,'error'=>null]);$j->update($job,$this->now());return resolve($this->get($c['request_id'],$clients,$pairedPbx));
        }
        $this->polling[$c['request_id']]=true;
        try{$promise=($this->commandTransport($pairedPbx))('POST','/control/v1/routing/requests',$c);}catch(\Throwable $e){$promise=\React\Promise\reject($e);}
        // Accept asynchronously: callers poll the same UUID, never block waiting for application.
        $promise->then(fn($r)=>$this->receive($c,$r,false),fn(\Throwable $e)=>$this->markUnknown($c,$e instanceof ControlError?$e->reason:'state_unknown'))->then(function()use($c){unset($this->polling[$c['request_id']]);$this->refresh();},function()use($c){unset($this->polling[$c['request_id']]);});
        return resolve($this->get($c['request_id'],$clients,$pairedPbx));
    }
    private function markUnknown(array $c,string $reason): void
    {
        $job=$this->journal()->get($c['request_id'])['job'];$job['state']='unknown';$job['updated_at']=$this->stamp();$job['error']=(new ControlError($reason))->payload();$this->journal()->update($job,$this->now());
    }
    private function receive(array $c,array $response,bool $read): void
    {
        $b=$response['body'];$status=$response['status'];
        if(!is_array($b)||!TelephonyContract::valid('CommandResponse',$b,true)){$this->markUnknown($c,'state_unknown');return;}
        $b=TelephonyContract::project('CommandResponse',$b);$job=$b['request'];
        if($job===null){
            $code=$b['error']['code'];
            if($read&&$status===404&&$code==='request_not_found'){
                if($this->now()<=TelephonyContract::timestamp($c['expires_at']))return;
                $job=$this->journal()->get($c['request_id'])['job'];$job['state']='expired';$job['updated_at']=$this->stamp();$job['error']=(new ControlError('request_expired'))->payload();$this->journal()->update($job,$this->now());return;
            }
            // A definite POST rejection is terminal; malformed/server errors remain uncertain.
            if(!$read&&in_array($status,[400,403,409,501],true)&&ControlError::statusFor($code)===$status){$job=$this->journal()->get($c['request_id'])['job'];$job['state']='rejected';$job['updated_at']=$this->stamp();$job['error']=(new ControlError($code,$b['error']['current_revision'],$b['error']['current_pbx_epoch']))->payload();$this->journal()->update($job,$this->now());return;}
            $this->markUnknown($c,'state_unknown');return;
        }
        foreach($c as $k=>$value)if(($job[$k]??null)!==$value){$this->markUnknown($c,'state_unknown');return;}
        if($job['error']!==$b['error'] || ($read&&$status!==200) || (!$read&&!in_array($status,[200,202,400,403,409,501,503,504],true)) || ($job['result_revision']!==null&&explode(':',$job['result_revision'])[1]!==$c['pbx_epoch'])){$this->markUnknown($c,'state_unknown');return;}
        if($job['error']!==null)$job['error']=(new ControlError($job['error']['code'],$job['error']['current_revision'],$job['error']['current_pbx_epoch']))->payload();
        $this->journal()->update($job,$this->now());
    }
    public function poll(): void
    {
        if($this->demo||!$this->enabled())return;
        try{$ids=$this->journal()->pending();}catch(\Throwable){return;}
        foreach($ids as $id){
            if(count($this->polling)>=8)break;
            if(isset($this->polling[$id]))continue;
            try {
                $row=$this->journal()->get($id);if(!$row)continue;$c=$row['command'];
                $interval=$this->now()>TelephonyContract::timestamp($c['expires_at'])?5:2;
                if($this->tick()-($this->lastPoll[$id]??-INF)<$interval)continue;
                $this->polling[$id]=true;$this->lastPoll[$id]=$this->tick();
                if ($row['paired_pbx'] !== null && (!$this->paired() || $row['paired_pbx'] !== $this->config['telephony_control']['pbx_id'])) {
                    unset($this->polling[$id]); continue;
                }
                ($this->commandTransport($row['paired_pbx']))('GET','/control/v1/routing/requests/'.$id,null)->then(fn($r)=>$this->receive($c,$r,true),fn()=>null)->then(function()use($id){unset($this->polling[$id]);},function()use($id){unset($this->polling[$id]);});
            } catch(\Throwable) { unset($this->polling[$id]); }
        }
    }
}
