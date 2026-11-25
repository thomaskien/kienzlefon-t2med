<?php
declare(strict_types=1);
namespace Kienzlefon;

/** Executes the pinned contract vocabulary; rejects unsupported schema keywords in tests. */
final class TelephonyContract
{
    private static ?array $definitions = null;
    private static function definitions(): array
    {
        return self::$definitions ??= json_decode(file_get_contents(dirname(__DIR__).'/resources/telephony-v1.schema.json'),true,64,JSON_THROW_ON_ERROR)['$defs'];
    }
    private static function expand(array $schema): array
    {
        if (isset($schema['$ref'])) return self::definitions()[substr($schema['$ref'],8)];
        return $schema;
    }
    public static function valid(string $kind, mixed $value, bool $response = false): bool
    {
        return self::matches(self::definitions()[$kind],$value,$response);
    }
    public static function timestamp(mixed $value): ?float
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\d[Tt]\d\d:\d\d:\d\d(?:\.\d+)?(?:[Zz]|[+-]\d\d:\d\d)$/D',$value)) return null;
        $parsed=date_parse($value);
        if ($parsed['warning_count'] || $parsed['error_count']) return null;
        try { return (float)(new \DateTimeImmutable($value))->format('U.u'); } catch (\Throwable) { return null; }
    }
    private static function matches(array $schema, mixed $v, bool $response): bool
    {
        $schema=self::expand($schema);
        if (isset($schema['const']) && $v !== $schema['const']) return false;
        if (array_key_exists('const',$schema) && $schema['const'] === null && $v !== null) return false;
        if (isset($schema['enum']) && !in_array($v,$schema['enum'],true)) return false;
        foreach (['allOf','anyOf','oneOf'] as $op) if (isset($schema[$op])) {
            $n=0; foreach ($schema[$op] as $s) if (self::matches($s,$v,$response)) $n++;
            if (($op==='allOf' && $n!==count($schema[$op])) || ($op==='anyOf' && !$n) || ($op==='oneOf' && $n!==1)) return false;
        }
        if (isset($schema['not']) && self::matches($schema['not'],$v,$response)) return false;
        if (isset($schema['if'])) {
            $branch=self::matches($schema['if'],$v,$response) ? 'then' : 'else';
            if (isset($schema[$branch]) && !self::matches($schema[$branch],$v,$response)) return false;
        }
        if (isset($schema['type'])) {
            $types=(array)$schema['type']; $ok=false;
            foreach ($types as $type) $ok=$ok || match($type) {
                'object'=>is_array($v) && (!array_is_list($v) || $v===[]), 'array'=>is_array($v) && array_is_list($v),
                'string'=>is_string($v), 'boolean'=>is_bool($v), 'integer'=>is_int($v), 'number'=>is_int($v)||is_float($v), 'null'=>$v===null,
                default=>false,
            };
            if (!$ok) return false;
        }
        if (is_array($v) && (isset($schema['properties']) || isset($schema['required']))) {
            foreach ($schema['required'] ?? [] as $key) if (!array_key_exists($key,$v)) return false;
            foreach ($v as $key=>$item) {
                if (isset($schema['properties'][$key])) { if (!self::matches($schema['properties'][$key],$item,$response)) return false; }
                elseif (!$response && ($schema['additionalProperties'] ?? true)===false) return false;
            }
        }
        if (is_array($v) && array_is_list($v)) {
            if (count($v)<($schema['minItems']??0) || count($v)>($schema['maxItems']??PHP_INT_MAX)) return false;
            foreach ($v as $item) if (isset($schema['items']) && !self::matches($schema['items'],$item,$response)) return false;
            if (($schema['uniqueItems']??false) && count(array_unique(array_map(static fn($x)=>json_encode($x),$v)))!==count($v)) return false;
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v,'UTF-8') || mb_strlen($v)<($schema['minLength']??0) || mb_strlen($v)>($schema['maxLength']??PHP_INT_MAX)) return false;
            if (isset($schema['pattern']) && !preg_match('~'.str_replace('~','\\~',$schema['pattern']).'~D',$v)) return false;
            if (($schema['format']??'')==='date-time' && self::timestamp($v)===null) return false;
            if (($schema['format']??'')==='date' && (!preg_match('/^(\d{4})-(\d\d)-(\d\d)$/D',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))) return false;
        }
        if (is_int($v)||is_float($v)) if ($v<($schema['minimum']??-INF)||$v>($schema['maximum']??INF)) return false;
        return true;
    }
    public static function project(string $kind, array $value): array { return self::pick(self::definitions()[$kind],$value); }
    private static function pick(array $s, mixed $v): mixed
    {
        $s=self::expand($s);
        if (!isset($s['properties']) && isset($s['anyOf'])) foreach ($s['anyOf'] as $option) if (self::matches($option,$v,true)) return self::pick($option,$v);
        if (is_array($v) && isset($s['properties'])) {
            $out=[]; foreach ($s['properties'] as $k=>$sub) if (array_key_exists($k,$v)) $out[$k]=self::pick($sub,$v[$k]); return $out;
        }
        if (is_array($v) && isset($s['items'])) return array_map(fn($item)=>self::pick($s['items'],$item),$v);
        return $v;
    }
    public static function routingSemantics(array $r, array $extensions): bool
    {
        $groups=array_column($r['groups'],'available','id');
        if (count($groups)!==2 || !array_key_exists('normal',$groups)||!array_key_exists('specialist',$groups)) return false;
        if ($r['available'] && explode(':',$r['revision'])[1]!==$r['pbx_epoch']) return false;
        if (count(array_unique(array_column($extensions,'id')))!==count($extensions)) return false;
        foreach ($extensions as $e) foreach ($groups as $g=>$available) {
            $member=$e['routing'][$g]; $p=$e['routing']['permissions'][$g]; $write=$p['can_enable']||$p['can_disable'];
            if (!$r['available'] && ($member!==null||$write)) return false;
            if ((!$r['enabled']||$member===null||!$available) && $write) return false;
            if ($p['disable_reason']==='last_member' && ($member!==true || count(array_filter($extensions,fn($x)=>$x['routing'][$g]===true))!==1)) return false;
        }
        return true;
    }
    public static function command(array $input): array
    {
        if (!self::valid('RoutingCommand',$input) || explode(':',$input['expected_revision'])[1]!==$input['pbx_epoch']) throw new ControlError('invalid_request');
        ksort($input); return $input;
    }
    public static function secret(string $file): string
    {
        clearstatcache(true,$file);
        if (is_link($file) || !is_file($file) || (fileperms($file)&0777)!==0600 || filesize($file)>66) throw new ControlError('state_unavailable');
        $raw=@file_get_contents($file);
        if (!is_string($raw) || !preg_match('/^[a-fA-F0-9]{64}(?:\r?\n)?$/D',$raw)) throw new ControlError('state_unavailable');
        return rtrim($raw,"\r\n");
    }
}
