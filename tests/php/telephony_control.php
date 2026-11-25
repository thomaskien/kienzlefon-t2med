<?php
use React\Http\Message\ServerRequest;
use Kienzlefon\{Config,Runtime,HttpApi,TelephonyContract as Contract, TelephonyControl as Control, ControlError, RoutingJournal};

function controlFixture(string $name): array {
    foreach(json_decode(file_get_contents(dirname(__DIR__,2).'/docs/telefonie-v1/fixtures.json'),true)['fixtures'] as $f)if($f['name']===$name)return $f['body'];
    throw new RuntimeException('Fixture missing '.$name);
}
function settled($promise) { $value=null;$error=null;$promise->then(function($v)use(&$value){$value=$v;},function($e)use(&$error){$error=$e;});if($error)throw $error;return $value; }
function failsCode(callable $fn,string $code): void { try{$fn();}catch(ControlError $e){eq($e->reason,$code);return;}throw new RuntimeException('Expected '.$code); }
function controlEnv(): array {
    $dir=sys_get_temp_dir().'/kf-control-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    $c=Config::validate(config());$c['telephony_control']['enabled']=true;$c['telephony_control']['request_database']=$dir.'/requests.sqlite';
    $overview=controlFixture('overview-current');$state=['schema_version'=>1,'pbx_id'=>'test-pbx','pbx_epoch'=>$overview['routing_control']['pbx_epoch'],'generated_at'=>$overview['generated_at'],'live_counters'=>$overview['live_counters'],'routing_control'=>$overview['routing_control'],'extensions'=>array_map(fn($e)=>array_intersect_key($e,array_flip(['id','label','routing'])),$overview['extensions'])];
    $c['telephony_control']['pbx_id']='test-pbx';$now=Contract::timestamp($state['generated_at']);
    $caps=['schema_version'=>1,'pbx_id'=>'test-pbx','features'=>['live_counters'=>true,'routing_control'=>true,'statistics_events'=>true],'limits'=>['command_ttl_seconds'=>30,'max_body_bytes'=>8192,'max_age_ms'=>10000,'request_retention_seconds'=>604800]];
    $command=['request_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','client_id'=>'empfang-1','extension'=>'201','route'=>'normal','member'=>false,'expected_revision'=>$state['routing_control']['revision'],'pbx_epoch'=>$state['pbx_epoch'],'expires_at'=>gmdate('c',(int)$now+30)];
    return [$dir,$c,$state,$caps,$command,$now,$overview];
}
function cleanControl(string $dir): void { foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir); }

test('Control: PHP validates every pinned schema fixture',function(){
    $fixtures=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/telefonie-v1/fixtures.json'),true)['fixtures'];
    foreach($fixtures as $f)if(Contract::valid($f['schema'],$f['body'])!==$f['valid'])throw new RuntimeException($f['name']);
    eq(hash_file('sha256',dirname(__DIR__,2).'/resources/telephony-v1.schema.json'),hash_file('sha256',dirname(__DIR__,2).'/docs/telefonie-v1/schema.json'));
});
test('Control: current parts, transport age, stale state and partial failure are isolated',function(){
    [$dir,$c,$state,$caps,$command,$now,$overview]=controlEnv();$mono=100.;$mode='ok';
    $send=function($method,$path)use(&$mode,&$state,$caps){if($mode==='offline')return React\Promise\reject(new ControlError('upstream_unreachable'));return React\Promise\resolve(['status'=>200,'body'=>str_ends_with($path,'capabilities')?$caps:$state]);};
    $control=new Control($c,false,$send,fn()=>$now,function()use(&$mono){return $mono;});
    try {
        settled($control->refresh());$v=$control->view($overview['extensions'],$now,$overview['call_statistics']);eq(Contract::valid('Overview',$v),true);eq($v['live_counters']['waiting'],2);eq($v['routing_control']['available'],true);
        $mono+=10;$v=$control->view($overview['extensions'],$now,$overview['call_statistics']);eq($v['live_counters']['waiting'],null);eq($v['routing_control']['available'],false);
        settled($control->refresh());$state['routing_control']['revision']='bad';settled($control->refresh());$v=$control->view($overview['extensions'],$now,$overview['call_statistics']);eq($v['live_counters']['status'],'current');eq($v['routing_control']['available'],false);eq($v['extensions'][0]['state'],'idle');eq($v['call_statistics'],$overview['call_statistics']);eq(Contract::valid('Overview',$v),true);
        $state['pbx_id']='wrong';settled($control->refresh());$v=$control->view([],$now,[]);eq($v['live_counters']['status'],'unavailable');eq($v['capabilities']['live_counters'],true);
        $mode='offline';settled($control->refresh());eq($control->view([],null,[])['routing_control']['reason_code'],'source_disconnected');
    }finally{cleanControl($dir);}
});
test('Control: paired scope fails closed on wrong practice, malformed grant or missing endpoint', function () {
    [$dir, $config] = controlEnv();
    $config['telephony_control']['routing_token_file'] = $dir.'/writer';
    $scope = ['schema_version'=>1, 'pbx_id'=>'test-pbx', 'remote_enabled'=>true, 'mode'=>'all', 'client_ids'=>[]];
    $response = ['status'=>200, 'body'=>$scope];
    $send = function ($method, $path) use (&$response) {
        eq([$method,$path], ['GET','/control/v1/routing/workplaces']);
        return React\Promise\resolve($response);
    };
    $control = new Control($config, false, $send);
    try {
        eq(settled($control->workplaces()), $scope);
        foreach ([['pbx_id'=>'other'], ['client_ids'=>new stdClass()], ['remote_enabled'=>1],
                  ['mode'=>'selected','client_ids'=>['a','a']], ['mode'=>'all','client_ids'=>['a']]] as $invalid) {
            $response['body'] = array_replace($scope, $invalid);
            failsCode(fn() => settled($control->workplaces()), 'state_unavailable');
        }
        foreach ([401=>'upstream_authentication_failed', 404=>'state_unavailable', 501=>'state_unavailable', 503=>'upstream_unreachable'] as $status=>$reason) {
            $response = ['status'=>$status,'body'=>null];
            failsCode(fn() => settled($control->workplaces()), $reason);
        }
    } finally { cleanControl($dir); }
});

test('Control: eine inaktive Nebenstelle aus der Fon-Anlage erscheint nicht als Arbeitsplatz',function(){
    [$dir,$config,$state,$caps,$command,$now]=controlEnv();
    $hidden=$state['extensions'][0]['id'];
    $config['telephony']['inactive_extensions']=[$hidden];
    $send=fn($method,$path)=>React\Promise\resolve(['status'=>200,'body'=>str_ends_with($path,'capabilities')?$caps:$state]);
    $control=new Control($config,false,$send,fn()=>$now);
    try {
        settled($control->refresh());
        $view=$control->view([],null,[]);
        eq($view['routing_control']['available'],true);
        eq(in_array($hidden,array_column($view['extensions'],'id'),true),false);
        failsCode(fn()=>$control->submit(array_replace($command,['extension'=>$hidden])),'unknown_extension');
    } finally {cleanControl($dir);}
});
test('Control: lost reply survives middleware restart and never repeats POST',function(){
    [$dir,$c,$state,$caps,$cmd,$now,$overview]=controlEnv();$mono=100.;$posts=0;$queries=0;$response=null;
    $send=function($method,$path,$body)use(&$posts,&$queries,&$response,$state,$caps){
        if($method==='POST'){$posts++;return React\Promise\reject(new ControlError('upstream_timeout'));}
        if(str_contains($path,'requests/')){$queries++;return React\Promise\resolve($response??['status'=>404,'body'=>['request'=>null,'error'=>(new ControlError('request_not_found'))->payload()]]);}
        return React\Promise\resolve(['status'=>200,'body'=>str_ends_with($path,'capabilities')?$caps:$state]);
    };
    $make=fn()=>new Control($c,false,$send,fn()=>$now,fn()=>$mono);
    try{
        $a=$make();settled($a->refresh());$r=settled($a->submit($cmd));eq($r['request']['state'],'unknown');eq($posts,1);eq(Contract::valid('CommandResponse',$r),true);
        $a=$make();settled($a->refresh());eq(settled($a->submit($cmd))['request']['state'],'unknown');eq($posts,1);
        $changed=$cmd;$changed['member']=true;failsCode(fn()=>$a->submit($changed),'request_id_conflict');
        failsCode(fn()=>$a->get($cmd['request_id'],['not-allowed']),'client_forbidden');
        $a->poll();eq($queries,1);eq($a->get($cmd['request_id'])['request']['state'],'unknown');
        $job=$r['request'];$job=array_replace($job,['state'=>'applied','error'=>null,'applied_at'=>gmdate('c',(int)$now),'result_revision'=>'r1:'.$cmd['pbx_epoch'].':18','changed'=>true]);$response=['status'=>200,'body'=>['request'=>$job,'error'=>null]];
        $a=$make();$a->poll();eq($a->get($cmd['request_id'])['request']['state'],'applied');eq($posts,1);
        $j=new RoutingJournal($c['telephony_control']['request_database']);$j->prune($now+604799);eq($j->get($cmd['request_id'])!==null,true);$j->prune($now+604801);eq($j->get($cmd['request_id']),null);
    }finally{cleanControl($dir);}
});
test('Control: expired unknown request requires confirmed absence; no reapply after new epoch',function(){
    [$dir,$c,$state,$caps,$cmd,$now]=controlEnv();$mono=0.;$offline=true;$posts=0;
    $send=function($method,$path,$body)use(&$posts,&$offline,&$state,$caps){if($method==='POST'){$posts++;return React\Promise\reject(new ControlError('upstream_timeout'));}if(str_contains($path,'requests/'))return $offline?React\Promise\reject(new ControlError('upstream_unreachable')):React\Promise\resolve(['status'=>404,'body'=>['request'=>null,'error'=>(new ControlError('request_not_found'))->payload()]]);return React\Promise\resolve(['status'=>200,'body'=>str_ends_with($path,'capabilities')?$caps:$state]);};
    $a=new Control($c,false,$send,function()use(&$now){return $now;},function()use(&$mono){return $mono;});
    try{settled($a->refresh());settled($a->submit($cmd));$now+=40;$mono+=40;$a->poll();eq($a->get($cmd['request_id'])['request']['state'],'unknown');$offline=false;$mono+=6;$a->poll();eq($a->get($cmd['request_id'])['request']['state'],'expired');eq($posts,1);eq(Contract::valid('CommandResponse',$a->get($cmd['request_id'])),true);}finally{cleanControl($dir);}
});
test('Control: per direction permission, unknown membership, last member, epoch, revision and expiry',function(){
    [$dir,$c,$state,$caps,$cmd,$now]=controlEnv();$posts=0;
    $send=function($method,$path,$body)use(&$state,$caps,&$posts){if($method==='POST'){$posts++;throw new RuntimeException('Unexpected POST');}return React\Promise\resolve(['status'=>200,'body'=>str_ends_with($path,'capabilities')?$caps:$state]);};
    $a=new Control($c,false,$send,fn()=>$now);
    try{
        settled($a->refresh());$bad=$cmd;$bad['expected_revision']='r1:'.$cmd['pbx_epoch'].':1';failsCode(fn()=>$a->submit($bad),'revision_conflict');
        $bad=$cmd;$bad['expires_at']=gmdate('c',(int)$now);failsCode(fn()=>$a->submit($bad),'request_expired');
        $bad=$cmd;$bad['pbx_epoch']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';$bad['expected_revision']='r1:'.$bad['pbx_epoch'].':1';failsCode(fn()=>$a->submit($bad),'pbx_restarted');
        $state['extensions'][0]['routing']['permissions']['normal']['can_disable']=false;$state['extensions'][0]['routing']['permissions']['normal']['disable_reason']='admin_locked';settled($a->refresh());failsCode(fn()=>$a->submit($cmd),'admin_locked');
        foreach($state['extensions'] as &$e)$e['routing']['normal']=$e['id']==='201';unset($e);$state['extensions'][0]['routing']['permissions']['normal']['can_disable']=true;$state['extensions'][0]['routing']['permissions']['normal']['disable_reason']=null;settled($a->refresh());failsCode(fn()=>$a->submit($cmd),'last_member');
        $state['extensions'][0]['routing']['normal']=null;settled($a->refresh());failsCode(fn()=>$a->submit($cmd),'state_unavailable');eq($posts,0);
    }finally{cleanControl($dir);}
});
test('Control: scoped app token cannot access bootstrap, FHIR, patient search, GDT or dialing',function(){
    [$dir,$c]=controlEnv();$c['app_access']=['enabled'=>true,'token_file'=>$dir.'/app.token','allowed_clients'=>['empfang-1'],'allowed_networks'=>['127.0.0.1/32']];$secret=str_repeat('a',64);file_put_contents($dir.'/app.token',$secret);chmod($dir.'/app.token',0600);
    $runtime=new Runtime($c,true);$api=new HttpApi($runtime);
    $req=function($path,$method='GET',$headers=[],$body='')use($api,$secret){return settled(React\Promise\resolve($api(new ServerRequest($method,'http://localhost:8766'.$path,['Authorization'=>'Bearer '.$secret]+$headers,$body,'1.1',['REMOTE_ADDR'=>'127.0.0.1']))));};
    try{
        $r=$req('/api/v1/telephony/overview');eq($r->getStatusCode(),200);$v=json_decode((string)$r->getBody(),true);eq(Contract::valid('Overview',$v),true);eq(isset($v['calls']),false);eq(isset($v['patient_directory']),false);
        foreach(['/api/v1/bootstrap','/api/v1/telephony','/api/v1/fhir/status','/api/v1/patients/search','/api/v1/patient-open','/api/v1/calls'] as $path)eq($req($path)->getStatusCode(),403);
        eq($req('/api/v1/extension-routing','POST',['Content-Type'=>'text/plain'])->getStatusCode(),415);
        eq($req('/api/v1/telephony/overview','GET',['Origin'=>'http://evil.example'])->getStatusCode(),403);
        file_put_contents($dir.'/app.token',str_repeat('b',64));eq($req('/api/v1/telephony/overview')->getStatusCode(),401);
    }finally{$runtime->stop();cleanControl($dir);}
});

test('Control: key modes, distinct secrets and private independent journal paths',function(){
    [$dir,$c]=controlEnv();
    try{
        $file=$dir.'/key';file_put_contents($file,str_repeat('a',64));chmod($file,0640);rejects(fn()=>Contract::secret($file));chmod($file,0600);eq(strlen(Contract::secret($file)),64);
        $c['telephony_control']['token_file']=$file;$c['app_access']['token_file']=$dir.'/second';file_put_contents($dir.'/second',str_repeat('a',64));chmod($dir.'/second',0600);$c['app_access']['enabled']=true;rejects(fn()=>Config::checkServiceKeys($c));
        $c['app_access']['enabled']=false;$c['telephony_control']['request_database']=$c['fhir']['database'];rejects(fn()=>Config::validate($c));
        $c['telephony_control']['request_database']=$dir.'/../requests.sqlite';rejects(fn()=>Config::validate($c));
        $foreign=$dir.'/foreign.sqlite';$db=new PDO('sqlite:'.$foreign);$db->exec('CREATE TABLE personal (value TEXT); INSERT INTO personal VALUES ("keep")');unset($db);$hash=hash_file('sha256',$foreign);rejects(fn()=>new RoutingJournal($foreign));eq(hash_file('sha256',$foreign),$hash);
    }finally{cleanControl($dir);}
});
test('Control: future response properties are discarded, never exposed',function(){
    $job=controlFixture('overview-current');$job['patient_name']='not-for-app';$job['extensions'][0]['patient']='private';$job['live_counters']['secret']='private';
    eq(Contract::valid('Overview',$job,true),true);$safe=Contract::project('Overview',$job);eq(isset($safe['patient_name']),false);eq(isset($safe['extensions'][0]['patient']),false);eq(isset($safe['live_counters']['secret']),false);
    $reply=['request'=>null,'error'=>(new ControlError('request_not_found'))->payload()];eq(Contract::project('CommandResponse',$reply),$reply);
});

test('Practice inbox and phone have separate scopes, exact patient selection and both transfer modes',function(){
    [$dir,$c]=controlEnv();
    $base=$dir.'/app.token';
    $c['app_access']=['enabled'=>true,'token_file'=>$base,'allowed_clients'=>[],'allowed_networks'=>['127.0.0.1/32']];
    $keys=['lookup'=>bin2hex(random_bytes(32)), 'workplace'=>bin2hex(random_bytes(32))];
    foreach ($keys as $role=>$key) {file_put_contents($base.'.'.$role,$key);chmod($base.'.'.$role,0600);}
    $api=new HttpApi(new Runtime($c,true));
    $req=static function(string $action,string $role,array $data)use($api,$keys){
        $r=settled(React\Promise\resolve($api(new ServerRequest('POST','http://localhost:8766/api/v1/practice/'.$action,
            ['Authorization'=>'Bearer '.$keys[$role],'Content-Type'=>'application/json'],json_encode($data),'1.1',['REMOTE_ADDR'=>'127.0.0.1']))));
        return [$r->getStatusCode(),json_decode((string)$r->getBody(),true)];
    };
    $uuid=static fn()=>sprintf('00000000-0000-4000-8000-%012x',random_int(1,PHP_INT_MAX>>20));
    $phone=['number'=>'03055501022']; $seat=['client_id'=>'empfang-1'];
    try {
        eq($req('dial','lookup',$seat+$phone)[0],401);
        eq($req('lookup','workplace',$phone)[0],401);
        eq($req('lookup','lookup',['abfrage'=>'namensfelder']),[200,['namensfelder'=>true]]);
        [$status,$matches]=$req('lookup','lookup',$phone);
        eq($status,200); eq($matches['status'],'ambiguous');
        eq(array_column($matches['treffer'],'patientennummer'),[90002,90003]);
        eq($matches['treffer'][0]['geburtsdatum'],'1977-08-03');
        eq($req('patient','workplace',$seat+$phone+['patient_number'=>90001,'request_id'=>$uuid()])[0],400);
        eq($req('patient','workplace',$seat+$phone+['patient_number'=>90003,'request_id'=>$uuid()])[0],200);
        $call=$req('desk','workplace',$seat)[1]['calls'][0];
        eq($call['can_transfer'],true);
        eq($matches['namensfelder'],true);
        eq($call['treffer'],[['patientennummer'=>90001,'vorname'=>'Clara','nachname'=>'Sommer','name'=>'Clara Sommer','geburtsdatum'=>'1984-04-12']]);
        eq($req('dial','workplace',$seat+['number'=>'202','request_id'=>$uuid()])[1]['extension'],'201');
        foreach (['cancel','complete','blind'] as $end) {
            $id=$uuid(); $input=$seat+['call_id'=>'demo-3','request_id'=>$id,'target_type'=>'internal','target'=>'202','mode'=>$end==='blind'?'blind':'attended'];
            [$status,$job]=$req('transfer','workplace',$input);
            eq($status,200); eq($job['phase'],$end==='blind'?'transferred':'consulting');
            if ($end!=='blind') eq($req($end,'workplace',$seat+['transfer_id'=>$id,'request_id'=>$uuid()])[1]['phase'],$end==='complete'?'transferred':'cancelled');
        }
    } finally {cleanControl($dir);}
});

test('Praxisqueue meldet Klingeln und verbundene Gespräche mit Aktentreffern pro Arbeitsplatz',function(){
    [$dir,$c]=controlEnv();
    $base=$dir.'/access'; $key=bin2hex(random_bytes(32));
    $c['app_access']=['enabled'=>true,'token_file'=>$base,'allowed_networks'=>['127.0.0.1/32']];
    $c['clients']['empfang-2']=['extension'=>'202','directory'=>'empfang-2','label'=>'Empfang 2'];
    $c['fhir']['database']=$dir.'/patients.sqlite';
    $c['call_statistics']['database_path']=$dir.'/statistics.sqlite';
    try {
        file_put_contents($base.'.workplace',$key); chmod($base.'.workplace',0600);
        $directory=new Kienzlefon\PatientDirectory($c['fhir']);
        $directory->replace([directoryPatient('a',41),directoryPatient('b',42)]);
        $runtime=new Runtime($c);
        $state=(new ReflectionProperty(Runtime::class,'state'))->getValue($runtime);
        $api=new HttpApi($runtime);
        $calls=static function(string $seat)use($api,$key){
            $response=settled(React\Promise\resolve($api(new ServerRequest('POST','http://localhost:8766/api/v1/practice/desk',
                ['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],json_encode(['client_id'=>$seat]),
                '1.1',['REMOTE_ADDR'=>'127.0.0.1']))));
            eq($response->getStatusCode(),200);
            return json_decode((string)$response->getBody(),true)['calls'];
        };
        $caller=channel('caller','PJSIP/trunk-0001','caller');
        $caller['ChannelStateDesc']='Up';
        $queue=['Queue'=>'praxis','Channel'=>$caller['Channel'],'Position'=>1];
        $state->beginSync(); $state->finishSync([$caller],[['Event'=>'QueueEntry']+$queue]);
        eq($calls('empfang-1'),[]);
        $state->event(['Event'=>'Newchannel']+channel('local','Local/202@queue-01;1','caller'));
        $state->event(['Event'=>'Newstate']+channel('phone','PJSIP/201-0002','caller'));
        eq($runtime->view()['calls'][0]['state'],'waiting');
        $ring=$calls('empfang-1');
        eq(count($ring),1); eq($ring[0]['state'],'ringing'); eq($ring[0]['can_transfer'],false);
        eq($ring[0]['number'],'03055501111'); eq($calls('empfang-2'),[]);
        settled($runtime->lookup($ring[0]['number']));
        $ring=$calls('empfang-1')[0];
        eq(array_column($ring['treffer'],'patientennummer'),[41,42]);
        $state->event(['Event'=>'Newstate']+channel('second','PJSIP/202-0003','caller'));
        eq($calls('empfang-2')[0]['state'],'ringing');
        $state->event(['Event'=>'Hangup','Uniqueid'=>'second']);
        $state->event(['Event'=>'QueueCallerLeave']+$queue);
        foreach (['caller','phone'] as $leg) $state->event(['Event'=>'BridgeEnter','Uniqueid'=>$leg,'ChannelStateDesc'=>'Up','BridgeUniqueid'=>'bridge']);
        $answered=$calls('empfang-1');
        eq(count($answered),1); eq($answered[0]['id'],$ring['id']); eq($answered[0]['state'],'connected');
        eq($answered[0]['treffer'],$ring['treffer']); eq($calls('empfang-2'),[]);
        $state->event(['Event'=>'Hangup','Uniqueid'=>'phone']); eq($calls('empfang-1'),[]);
    } finally {cleanControl($dir);}
});
