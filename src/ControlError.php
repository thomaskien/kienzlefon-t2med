<?php
declare(strict_types=1);
namespace Kienzlefon;

final class ControlError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $revision = null, public readonly ?string $epoch = null)
    {
        parent::__construct(self::messageFor($reason), self::statusFor($reason));
    }
    public static function statusFor(string $code): int
    {
        return match ($code) {
            'invalid_request','unknown_extension','unknown_route','unknown_client' => 400,
            'unauthenticated' => 401,
            'forbidden_origin','forbidden_network','csrf_failed','client_forbidden','global_disabled','admin_locked','not_eligible' => 403,
            'request_not_found','not_found' => 404,
            'method_not_allowed' => 405, 'payload_too_large' => 413, 'json_required' => 415,
            'revision_conflict','pbx_restarted','request_id_conflict','last_member','state_unknown','request_expired','route_unavailable' => 409,
            'not_supported' => 501, 'upstream_timeout' => 504,
            default => 503,
        };
    }
    public static function messageFor(string $code): string
    {
        return match ($code) {
            'revision_conflict' => 'Die Zuordnung oder Freigabe wurde geändert. Bitte den aktuellen Stand laden.',
            'pbx_restarted' => 'Die Telefonanlage wurde neu gestartet. Bitte den aktuellen Stand laden.',
            'request_id_conflict' => 'Diese Auftrags-ID wurde bereits für einen anderen Inhalt verwendet.',
            'last_member' => 'Der letzte Teilnehmer dieser Rufgruppe muss eingeschaltet bleiben.',
            'global_disabled' => 'Die Fernbedienung ist in der Administration gesperrt.',
            'admin_locked','not_eligible' => 'Diese Änderung ist für die Nebenstelle administrativ nicht freigegeben.',
            'unknown_extension' => 'Unbekannte Nebenstelle.', 'unknown_route' => 'Unbekannte Rufgruppe.',
            'unknown_client','client_forbidden' => 'Der Arbeitsplatz ist für diesen Zugang nicht freigegeben.',
            'request_expired' => 'Der Änderungsauftrag ist abgelaufen.',
            'request_not_found' => 'Der Auftrag ist nicht bekannt.',
            'not_supported' => 'Die Gegenstelle unterstützt diese Funktion noch nicht.',
            'upstream_timeout' => 'Die Antwort der Telefonanlage steht noch aus.',
            'upstream_unreachable' => 'Die Telefonanlage ist nicht erreichbar.',
            'upstream_authentication_failed' => 'Der Dienstzugang zur Telefonanlage muss geprüft werden.',
            'unauthenticated' => 'Dienstanmeldung erforderlich.',
            'forbidden_origin' => 'Der Browser-Ursprung ist für diese Middleware-Adresse nicht freigegeben.',
            'forbidden_network' => 'Das Quellnetz ist für diesen Zugang nicht freigegeben.',
            'csrf_failed' => 'Sitzung abgelaufen. Seite neu laden.',
            'state_unknown','state_unavailable' => 'Kein verlässlicher Anlagenzustand. Bitte den Abgleich abwarten.',
            'route_unavailable' => 'Diese Rufgruppe ist derzeit nicht verfügbar.',
            'json_required' => 'JSON erforderlich.', 'payload_too_large' => 'Anfrage zu groß.',
            'method_not_allowed' => 'Methode nicht erlaubt.', 'apply_failed' => 'Die Änderung wurde nicht angewendet.',
            default => 'Ungültige Anfrage.',
        };
    }
    public function payload(): array
    {
        return ['code'=>$this->reason,'message'=>$this->getMessage(),
            'retryable'=>in_array($this->reason,['upstream_timeout','upstream_unreachable','state_unavailable','state_unknown'],true),
            'current_revision'=>$this->revision,'current_pbx_epoch'=>$this->epoch];
    }
}
