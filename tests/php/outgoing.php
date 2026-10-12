<?php
use Kienzlefon\{Config,Runtime,Telephony};

final class OutgoingFixture
{
    public Runtime $runtime;
    public Telephony $state;
    public function __construct(bool $demo = true) {
        $c = config(); $c['clients']['empfang-2'] = ['label'=>'Empfang 2','extension'=>'202','directory'=>'empfang-2'];
        $this->runtime = new Runtime(Config::validate($c), $demo);
        $this->state = (new ReflectionProperty($this->runtime, 'state'))->getValue($this->runtime);
        $this->state->beginSync(); $this->state->finishSync([], []);
        if (!$demo) {
            $ami = (new ReflectionProperty($this->runtime, 'ami'))->getValue($this->runtime);
            (new ReflectionProperty($ami, 'ready'))->setValue($ami, true);
        }
    }
    public function input(int $n, string $client = 'empfang-1'): array {
        return ['client_id'=>$client,'extension'=>$client === 'empfang-1' ? '201' : '202','number'=>'03055501111','request_id'=>self::id($n)];
    }
    public static function id(int $n): string { return sprintf('12345678-1111-2222-3333-%012d', $n); }
    public function seed(int $n, string $phase = 'failed', string $client = 'empfang-1', array $extra = []): void {
        $calls = $this->all(); $input = $this->input($n, $client);
        $calls[$input['request_id']] = array_replace($input, ['phase'=>$phase,'created_at'=>gmdate('c'),'_created'=>microtime(true)-60,
            '_signature'=>$client.':'.$input['number'],'_source'=>'kf-'.$input['request_id'],'_target'=>'','_legs'=>[],
            '_answered'=>false,'_ever_connected'=>$phase === 'connected','_hidden'=>false,'_expires'=>microtime(true)+600], $extra);
        (new ReflectionProperty($this->runtime, 'outgoing'))->setValue($this->runtime, $calls);
    }
    public function all(): array { return (new ReflectionProperty($this->runtime, 'outgoing'))->getValue($this->runtime); }
    public function visible(): array { return $this->runtime->view()['outgoing']; }
    public function collect(): void { (new ReflectionMethod($this->runtime, 'collect'))->invoke($this->runtime); }
    public function reconcile(?float $time = null): void { (new ReflectionMethod($this->runtime, 'reconcileOutgoing'))->invoke($this->runtime, $time ?? microtime(true)); }
}

test('Rückruf erneut versucht dieselbe Nummer und Nebenstelle genau einmal', function () {
    $f = new OutgoingFixture(); $f->seed(1);
    $request = ['client_id'=>'empfang-1','request_id'=>$f::id(2),'number'=>'999','extension'=>'202'];
    $call = resultOf($f->runtime->retryCall($f::id(1), $request));
    eq($call['number'], '03055501111'); eq($call['extension'], '201'); eq($call['request_id'], $f::id(2));
    eq(count($f->all()), 2); eq($f->visible()[0]['can_retry'], false); eq($f->visible()[0]['retried_as'], $f::id(2));
    eq(resultOf($f->runtime->retryCall($f::id(1), $request))['request_id'], $f::id(2));
    rejects(fn() => $f->runtime->retryCall($f::id(1), array_replace($request, ['request_id'=>$f::id(3)])));
    eq(count($f->all()), 2);
    $f->runtime->dismissCall($f::id(2), ['client_id'=>'empfang-1']);
    eq(resultOf($f->runtime->retryCall($f::id(1), $request))['can_delete'], false);
    eq(count($f->all()), 2);
});
test('Rückrufaktionen validieren Arbeitsplatz und Vorgangs-ID', function () {
    $f = new OutgoingFixture(); $f->seed(1); $f->seed(2);
    rejects(fn() => $f->runtime->retryCall($f::id(1), ['client_id'=>'empfang-2','request_id'=>$f::id(3)]));
    rejects(fn() => $f->runtime->dismissCall($f::id(1), ['client_id'=>'empfang-2']));
    rejects(fn() => $f->runtime->cleanupCalls(['client_id'=>'unknown']));
    rejects(fn() => $f->runtime->retryCall($f::id(1), ['client_id'=>'empfang-1','request_id'=>$f::id(2)]));
    rejects(fn() => $f->runtime->dismissCall('bad-id', ['client_id'=>'empfang-1']));
    eq(count($f->visible()), 2);
});
test('Löschen blendet aktive Aufträge aus und erhält Doppelanrufschutz über zehn Minuten', function () {
    $f = new OutgoingFixture(); $f->seed(1, 'connected', extra:['_expires'=>microtime(true)-1]);
    eq($f->runtime->dismissCall($f::id(1), ['client_id'=>'empfang-1']), ['removed'=>[$f::id(1)]]);
    eq($f->visible(), []); $f->collect(); eq(count($f->all()), 1);
    eq(resultOf($f->runtime->originate($f->input(1)))['phase'], 'connected');
    rejects(fn() => $f->runtime->originate($f->input(2)));
    eq($f->runtime->dismissCall($f::id(1), ['client_id'=>'empfang-1']), ['removed'=>[$f::id(1)]]);
});
test('Liste bereinigen entfernt nur eigene erledigte Einträge und behält Deduplizierung', function () {
    $f = new OutgoingFixture(); $f->seed(1); $f->seed(2, 'ended'); $f->seed(3, 'unknown'); $f->seed(4, 'ringing_target'); $f->seed(5, 'failed', 'empfang-2');
    eq($f->runtime->cleanupCalls(['client_id'=>'empfang-1']), ['removed'=>[$f::id(1),$f::id(2)]]);
    eq(array_column($f->visible(), 'request_id'), [$f::id(3),$f::id(4),$f::id(5)]); eq(count($f->all()), 5);
    eq($f->runtime->cleanupCalls(['client_id'=>'empfang-1']), ['removed'=>[]]);
    eq(resultOf($f->runtime->originate($f->input(1)))['phase'], 'failed');
});
test('Nur erledigte Aufträge laufen automatisch ab, auch Demo-Historie', function () {
    $f = new OutgoingFixture(); $n = 0;
    foreach (['requested','ringing_extension','dialing_target','ringing_target','connected','unknown','ended','failed','demo'] as $phase) $f->seed(++$n, $phase, extra:['_expires'=>microtime(true)-1]);
    $f->collect(); eq(array_column($f->visible(), 'phase'), ['requested','ringing_extension','dialing_target','ringing_target','connected','unknown']);
});
test('Bestandsabgleich beendet verwaiste Rufaufträge und erhält zehn Minuten Historie', function () {
    $f = new OutgoingFixture(false); $n = 0;
    foreach (['requested','ringing_extension','dialing_target','ringing_target','connected','unknown'] as $phase) $f->seed(++$n, $phase, extra:['_expires'=>microtime(true)-1]);
    $f->reconcile(); eq(array_column($f->visible(), 'phase'), ['failed','failed','failed','failed','ended','failed']);
    $f->collect(); eq(count($f->all()), 6);
    foreach ($f->visible() as $call) { eq($call['can_retry'], true); eq($call['can_cleanup'], true); }
});
test('Junge Aufträge und veraltete Bestandsdaten erlauben keine voreilige Wiederwahl', function () {
    $f = new OutgoingFixture(false); $f->seed(1, 'dialing_target', extra:['_created'=>microtime(true)]);
    $f->reconcile(); eq($f->visible()[0]['phase'], 'dialing_target'); eq($f->visible()[0]['can_retry'], false);
    $f->seed(1, 'unknown'); $f->state->disconnect(); $f->reconcile(); eq($f->visible()[0]['phase'], 'unknown');
    $f->seed(2, 'failed'); eq($f->visible()[1]['can_retry'], false); eq($f->runtime->cleanupCalls(['client_id'=>'empfang-1']), ['removed'=>[]]);
});
test('Vor Snapshot angelegte und während Snapshot neu gestartete Aufträge bleiben getrennt', function () {
    $f = new OutgoingFixture(false); $started = microtime(true)-120;
    $f->seed(1, 'ringing_extension', extra:['_created'=>$started-60]);
    $f->seed(2, 'ringing_extension', extra:['_created'=>$started+1]);
    $f->reconcile($started); eq(array_column($f->visible(), 'phase'), ['failed','ringing_extension']);
});
test('Vorhandene Quell-, Ziel- und Local-Kanäle verhindern vorzeitigen Abschluss', function () {
    foreach (['_source','_target','_legs'] as $key) {
        $f = new OutgoingFixture(false); $f->seed(1, 'ringing_target', extra:[$key=>$key === '_legs' ? ['still-live'] : 'still-live']);
        $f->state->beginSync(); $f->state->finishSync([channel('still-live','PJSIP/201-1','still-live')], []);
        $f->reconcile(); eq($f->visible()[0]['phase'], 'ringing_target');
        $f->seed(2); eq($f->visible()[1]['can_retry'], false);
    }
});
test('Erledigt gemeldeter Auftrag mit verbleibendem Kanal wird nicht bereinigt', function () {
    $f = new OutgoingFixture(false); $f->seed(1, 'failed', extra:['_target'=>'still-live']);
    $f->state->beginSync(); $f->state->finishSync([channel('still-live','PJSIP/trunk-1','still-live')], []);
    eq($f->visible()[0]['can_cleanup'], false); eq($f->visible()[0]['can_retry'], false);
    eq($f->runtime->cleanupCalls(['client_id'=>'empfang-1']), ['removed'=>[]]);
});
