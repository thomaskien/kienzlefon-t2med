<?php
use Kienzlefon\{Config,Telephony,Transfers};
use function React\Promise\resolve;
use function React\Promise\reject;

function resultOf($promise): array {
    $value = null; $error = null;
    $promise->then(function ($v) use (&$value) { $value=$v; }, function ($e) use (&$error) { $error=$e; });
    if ($error) throw $error;
    if ($value === null) throw new RuntimeException('Test-Promise noch offen.');
    return $value;
}
final class TransferFixture {
    public Telephony $state;
    public Transfers $transfers;
    public array $actions=[];
    public array $values=['KF_TRANSFER_READY'=>'1','FEATUREMAP(atxfer)'=>'*2','FEATURE(atxferabort)'=>'*3','FEATURE(atxfercomplete)'=>'*4'];
    public $hook=null;
    public function __construct() {
        $c=Config::validate(config()); $this->state=new Telephony($c['telephony']);
        $this->state->beginSync();$this->state->finishSync([channel('caller','PJSIP/trunk-0001','caller','initial'),channel('staff','PJSIP/201-0002','caller','initial')],[]);
        $this->transfers=new Transfers($this->state,function ($action,$fields) {
            $this->actions[]=['Action'=>$action]+$fields;
            if ($this->hook && ($response=($this->hook)($action,$fields)) !== null) return $response;
            return resolve(['Response'=>'Success','Value'=>$this->values[$fields['Variable'] ?? ''] ?? (str_starts_with($fields['Variable'] ?? '','DIALPLAN_EXISTS(') ? '1' : '')]);
        },$c);
    }
    public function input(array $replace=[]): array { return array_replace(['request_id'=>'12345678-1111-2222-3333-123456789000','client_id'=>'empfang-1','call_id'=>'caller','mode'=>'attended','target_type'=>'internal','target'=>'202'],$replace); }
    public function emit(array $e): void { $this->state->event($e); $this->transfers->event($e); }
    public function ringing(): void {
        $this->emit(['Event'=>'UserEvent','UserEvent'=>'KienzlefonTransferProgress','SchemaVersion'=>'1','RequestId'=>$this->input()['request_id'],'TransfererUniqueid'=>'staff','SourceUniqueid'=>'route','Phase'=>'dialing_target']);
        $this->emit(['Event'=>'BridgeLeave','Uniqueid'=>'staff']);
        $this->emit(['Event'=>'DialBegin','Uniqueid'=>'route','DestUniqueid'=>'target','DestChannel'=>'PJSIP/202-0003','Linkedid'=>'caller']);
    }
    public function consulting(): void {
        $this->ringing();
        $this->emit(['Event'=>'BridgeEnter','Uniqueid'=>'staff','BridgeUniqueid'=>'consult','ChannelStateDesc'=>'Up']);
        $this->emit(['Event'=>'BridgeEnter','Uniqueid'=>'target','BridgeUniqueid'=>'consult','ChannelStateDesc'=>'Up']);
    }
    public function job(): array { return $this->transfers->view()[0]; }
    public function sent(string $action): array { return array_values(array_filter($this->actions,fn($a)=>$a['Action']===$action)); }
    public function controlInput(): array { return ['client_id'=>'empfang-1','request_id'=>'12345678-1111-2222-3333-123456789001']; }
}

test('Transfer bindet nur eigene beantwortete Zweiergespräche',function () {
    $f=new TransferFixture();eq($f->state->transferPair('caller','201')['source_id'],'staff');
    rejects(fn()=>$f->state->transferPair('caller','202'));
    $f->emit(['Event'=>'Newstate','Uniqueid'=>'staff','ChannelStateDesc'=>'Ringing']); rejects(fn()=>$f->transfers->start($f->input()));eq($f->actions,[]);
    $f=new TransferFixture();$f->emit(['Event'=>'BridgeEnter','Uniqueid'=>'conference','Channel'=>'PJSIP/202-0009','Linkedid'=>'caller','BridgeUniqueid'=>'initial','ChannelStateDesc'=>'Up']);rejects(fn()=>$f->state->transferPair('caller','201'));
    $f=new TransferFixture();$f->state->beginSync();rejects(fn()=>$f->transfers->start($f->input()));eq($f->actions,[]);
});
test('Transfer erkennt eigene Verbindung durch Local-Kanäle',function () {
    $f=new TransferFixture();$f->state->beginSync();$f->state->finishSync([channel('caller','PJSIP/trunk-1','caller','b1'),channel('l1','Local/201@q-01;1','caller','b1'),channel('l2','Local/201@q-01;2','caller','b2'),channel('staff','PJSIP/201-2','caller','b2')],[]);
    eq($f->state->transferPair('caller','201')['peer_id'],'caller');
    eq($f->state->transferPair('caller','201')['transferee_id'],'l2');
    resultOf($f->transfers->start($f->input()));
    $written=array_values(array_unique(array_column($f->sent('Setvar'),'Channel')));
    eq($written,['PJSIP/201-2','PJSIP/trunk-1','Local/201@q-01;2']);
    $f->emit(['Event'=>'AttendedTransfer','Result'=>'Success','OrigTransfererUniqueid'=>'staff','TransfereeUniqueid'=>'l2','DestType'=>'Link']);
    eq($f->job()['phase'],'transferred');
});
test('Transfer blockiert fremde, eigene und ungültige Ziele',function () {
    foreach ([['target'=>'201'],['target'=>'999'],['target'=>[]],['target'=>"202\r\nAction: Hangup"],['target_type'=>'external','target'=>'202'],['mode'=>'anything'],['client_id'=>'unknown']] as $bad) {
        $f=new TransferFixture();rejects(fn()=>$f->transfers->start($f->input($bad)));eq($f->actions,[]);
    }
});
test('Direktvermittlung normalisiert externe Nummer und bleibt idempotent',function () {
    $f=new TransferFixture();$input=$f->input(['mode'=>'blind','target_type'=>'external','target'=>'491765550173']);
    $job=resultOf($f->transfers->start($input));eq($job['phase'],'transferred');eq($job['target'],'00491765550173');
    eq($f->sent('BlindTransfer'),[['Action'=>'BlindTransfer','Channel'=>'PJSIP/201-0002','Context'=>'kienzlefon-transfer','Exten'=>'00491765550173']]);
    resultOf($f->transfers->start($input));eq(count($f->sent('BlindTransfer')),1);
    rejects(fn()=>$f->transfers->start(array_replace($input,['target'=>'03055501111'])));
    eq(array_filter(array_keys($job),fn($key)=>str_starts_with($key,'_')),[]);
});
test('Frühes Dialplan-Ereignis verdeckt die bestätigte Direktvermittlung nicht',function () {
    $f=new TransferFixture();$f->hook=function ($action) use ($f) {
        if ($action === 'BlindTransfer') $f->emit(['Event'=>'UserEvent','UserEvent'=>'KienzlefonTransferProgress','SchemaVersion'=>'1','RequestId'=>$f->input()['request_id'],'TransfererUniqueid'=>'staff','SourceUniqueid'=>'caller','Phase'=>'dialing_target']);
        return null;
    };
    eq(resultOf($f->transfers->start($f->input(['mode'=>'blind'])))['phase'],'transferred');
});
test('Fehlende Telefonprojekt-Einrichtung verhindert jede Transfermutation',function () {
    $f=new TransferFixture();$f->values['KF_TRANSFER_READY']='0';$job=resultOf($f->transfers->start($f->input()));eq($job['phase'],'failed');eq($f->sent('Setvar'),[]);eq($f->sent('Atxfer'),[]);
    $f=new TransferFixture();$f->values['FEATURE(atxfercomplete)']='';eq(resultOf($f->transfers->start($f->input()))['phase'],'failed');eq($f->sent('Setvar'),[]);
});
test('Hangup während Transfer-Vorbereitung verhindert Native-Aktion',function () {
    $f=new TransferFixture();$f->hook=function ($action) use ($f) { if ($action==='Setvar') $f->emit(['Event'=>'Hangup','Uniqueid'=>'caller']); return null; };
    eq(resultOf($f->transfers->start($f->input()))['phase'],'failed');eq($f->sent('Atxfer'),[]);
});
test('Verlorene AMI-Bestätigung bleibt ungewiss ohne erneuten Transfer',function () {
    $f=new TransferFixture();$f->hook=fn($action)=>$action==='Atxfer' ? reject(new RuntimeException('Verbindung verloren')) : null;
    eq(resultOf($f->transfers->start($f->input()))['phase'],'unknown');resultOf($f->transfers->start($f->input()));eq(count($f->sent('Atxfer')),1);
    rejects(fn()=>$f->transfers->start($f->input(['request_id'=>'12345678-1111-2222-3333-123456789002'])));
    $f=new TransferFixture();$f->hook=fn($action)=>$action==='Atxfer' ? reject(new RuntimeException('Abgelehnt',403)) : null;
    eq(resultOf($f->transfers->start($f->input()))['phase'],'failed');
});
test('Rückfrage braucht Zielantwort und getrennte Brücke vor Abschluss',function () {
    $f=new TransferFixture();eq(resultOf($f->transfers->start($f->input()))['phase'],'requested');eq($f->job()['can_complete'],false);
    $f->ringing();eq($f->job()['can_cancel'],true);eq($f->job()['can_complete'],false);
    rejects(fn()=>$f->transfers->control($f->input()['request_id'],'complete',$f->controlInput()));
    $f->emit(['Event'=>'BridgeEnter','Uniqueid'=>'staff','BridgeUniqueid'=>'consult','ChannelStateDesc'=>'Up']);
    $f->emit(['Event'=>'BridgeEnter','Uniqueid'=>'target','BridgeUniqueid'=>'consult','ChannelStateDesc'=>'Ringing']);eq($f->job()['can_complete'],false);
    $f->emit(['Event'=>'Newstate','Uniqueid'=>'target','ChannelStateDesc'=>'Up']);eq($f->job()['phase'],'consulting');eq($f->job()['can_complete'],true);
    $job=resultOf($f->transfers->control($f->input()['request_id'],'complete',$f->controlInput()));eq($job['phase'],'completing');
    eq(array_column($f->sent('PlayDTMF'),'Digit'),['*','4']);eq(array_column($f->sent('PlayDTMF'),'Receive'),['true','true']);eq($f->sent('Hangup'),[]);
    resultOf($f->transfers->control($f->input()['request_id'],'complete',$f->controlInput()));eq(count($f->sent('PlayDTMF')),2);
    $f->emit(['Event'=>'UserEvent','UserEvent'=>'KienzlefonTransferProgress','SchemaVersion'=>'1','RequestId'=>$f->input()['request_id'],'TransfererUniqueid'=>'staff','SourceUniqueid'=>'route','Phase'=>'dialing_target']);
    eq($f->job()['phase'],'completing');eq($f->job()['can_complete'],false);
    $f->emit(['Event'=>'AttendedTransfer','Result'=>'Success','OrigTransfererUniqueid'=>'other','TransfereeUniqueid'=>'caller','DestType'=>'Bridge']);eq($f->job()['phase'],'completing');
    $f->emit(['Event'=>'AttendedTransfer','Result'=>'Success','OrigTransfererUniqueid'=>'staff','TransfereeUniqueid'=>'caller','DestType'=>'Bridge']);eq($f->job()['phase'],'transferred');
});
test('Abbruch kehrt erst bei bestätigter Ursprungsbrücke zurück',function () {
    $f=new TransferFixture();resultOf($f->transfers->start($f->input()));$f->consulting();
    rejects(fn()=>$f->transfers->control($f->input()['request_id'],'cancel',['client_id'=>'other','request_id'=>$f->controlInput()['request_id']]));
    eq(resultOf($f->transfers->control($f->input()['request_id'],'cancel',$f->controlInput()))['phase'],'cancelling');eq(count($f->sent('CancelAtxfer')),1);
    $f->emit(['Event'=>'BridgeLeave','Uniqueid'=>'staff']);$f->emit(['Event'=>'BridgeEnter','Uniqueid'=>'staff','BridgeUniqueid'=>'initial','ChannelStateDesc'=>'Up']);eq($f->job()['phase'],'cancelled');
});
test('Brückenwechsel vor dem Rückfrage-Dialplan gilt nicht als Abbruch',function () {
    $f=new TransferFixture();resultOf($f->transfers->start($f->input()));
    $f->emit(['Event'=>'BridgeLeave','Uniqueid'=>'staff']);
    $f->emit(['Event'=>'BridgeEnter','Uniqueid'=>'staff','BridgeUniqueid'=>'initial','ChannelStateDesc'=>'Up']);
    eq($f->job()['phase'],'requested');eq($f->job()['can_cancel'],false);
    $f->consulting();eq($f->job()['phase'],'consulting');
});
test('Rückfrage verliert bei AMI-Ausfall sofort die Steuermöglichkeiten',function () {
    $f=new TransferFixture();resultOf($f->transfers->start($f->input()));$f->consulting();$f->state->disconnect();$f->transfers->disconnect();
    eq($f->job()['phase'],'unknown');eq($f->job()['can_complete'],false);eq($f->job()['can_cancel'],false);
});
