<?php
declare(strict_types=1);
namespace Kienzlefon;

use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

/** Narrow, independently credentialed operations for the practice inbox and workstation UI. */
final class PracticeApp
{
    public static function handle(Runtime $runtime, ServerRequestInterface $request): array|PromiseInterface
    {
        $action = substr($request->getUri()->getPath(), strlen('/api/v1/practice/'));
        $access = $runtime->config['app_access'];
        if (!$access['enabled'] || !HttpApi::allowedAddress($request->getServerParams()['REMOTE_ADDR'] ?? '', $access['allowed_networks'])) {
            throw new ControlError('forbidden_network');
        }
        if ($request->getMethod() !== 'POST') throw new ControlError('method_not_allowed');
        $suffix = $action === 'lookup' ? 'lookup' : 'workplace';
        try { $token = TelephonyContract::secret($access['token_file'] . '.' . $suffix); }
        catch (\Throwable) { throw new ControlError('unauthenticated'); }
        if (!hash_equals('Bearer ' . $token, $request->getHeaderLine('Authorization'))) throw new ControlError('unauthenticated');
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') throw new ControlError('json_required');
        $body = (string)$request->getBody();
        if (strlen($body) > 8192) throw new ControlError('payload_too_large');
        $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new ControlError('invalid_request');
        if ($action === 'lookup') {
            if ($input === ['abfrage'=>'namensfelder']) return ['namensfelder'=>true];
            return $runtime->lookup($input['number'] ?? '')->then(static function ($result) {
                if (!in_array($result['status'], ['resolved','ambiguous','not_found'], true)) throw new ControlError('state_unavailable');
                return ['status'=>$result['status'], 'namensfelder'=>true, 'treffer'=>self::patientMatches($result['patients'])];
            });
        }
        $id = $input['client_id'] ?? '';
        if (!is_string($id) || !isset($runtime->config['clients'][$id])) throw new ControlError('unknown_client');
        $extension = $runtime->config['clients'][$id]['extension'];
        if ($action === 'desk') {
            $view = $runtime->view();
            $calls = [];
            foreach ($view['calls'] as $call) {
                $state = $call['workplace_states'][$extension] ?? null;
                if ($state === null) continue;
                $calls[] = ['id'=>$call['id'], 'state'=>$state, 'number'=>$call['number'],
                    'name'=>$call['name'] ?? '', 'treffer'=>self::patientMatches($call['patients']),
                    'can_transfer'=>$state === 'connected' && in_array($extension, $call['transfer_extensions'], true)];
            }
            $jobs = array_filter($view['transfers'], static fn($job) => $job['client_id'] === $id
                && !in_array($job['phase'], ['transferred','cancelled','failed'], true));
            return ['client_id'=>$id, 'extension'=>$extension, 'calls'=>($view['stale'] ?? false) ? [] : $calls, 'transfers'=>array_values($jobs)];
        }
        if ($action === 'dial') return $runtime->originate(['extension'=>$extension] + $input);
        if ($action === 'transfer') return $runtime->transfer($input);
        if ($action === 'complete' || $action === 'cancel') return $runtime->transferControl($input['transfer_id'] ?? '', $action, $input);
        if ($action === 'patient') {
            // Saved reports outlive lookup IDs. Confirm this exact candidate against the source again.
            return $runtime->lookup($input['number'] ?? '')->then(static fn($r) =>
                $runtime->openPatient(['lookup_id'=>$r['lookup_id']] + array_intersect_key($input,
                    array_flip(['client_id','patient_number','request_id']))));
        }
        throw new ControlError('not_found');
    }

    private static function patientMatches(array $patients): array
    {
        return array_map(static fn(array $p) => ['patientennummer'=>$p['patient_number'],
            'vorname'=>trim($p['first_name']), 'nachname'=>trim($p['last_name']),
            'name'=>Patients::displayName($p), 'geburtsdatum'=>self::birthDate($p['birth_date'] ?? '')], $patients);
    }

    private static function birthDate(string $date): ?string
    {
        if (!preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return "$m[1]-$m[2]-$m[3]";
    }
}
