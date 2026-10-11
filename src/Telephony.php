<?php
declare(strict_types=1);
namespace Kienzlefon;

final class Telephony
{
    private array $channels = [];
    private array $waiting = [];
    private array $members = [];
    private array $buffer = [];
    private bool $syncing = false;
    private bool $online = false;
    private bool $stale = true;
    public function __construct(private array $config) {}
    public function disconnect(): void { $this->online = false; $this->stale = true; $this->syncing = false; $this->buffer = []; $this->channels = []; $this->waiting = []; $this->members = []; }
    public function beginSync(): void { $this->online = true; $this->stale = true; $this->syncing = true; $this->buffer = []; }
    public function finishSync(array $channelFrames, array $queueFrames): void
    {
        if (!$this->syncing || !$this->online) return;
        $old = $this->channels;
        $this->channels = []; $this->waiting = []; $this->members = [];
        foreach ($channelFrames as $frame) if (($frame['Event'] ?? '') === 'CoreShowChannel') {
            $id = $frame['Uniqueid'] ?? '';
            if (!$id) continue;
            $frame['_created'] = microtime(true) - self::duration($frame['Duration'] ?? '0');
            if (isset($old[$id]['_connected']) && ($old[$id]['BridgeId'] ?? '') === ($frame['BridgeId'] ?? '')) $frame['_connected'] = $old[$id]['_connected'];
            $this->channels[$id] = $frame;
        }
        foreach ($queueFrames as $frame) $this->apply($frame);
        $buffer = $this->buffer;
        $this->syncing = false; $this->buffer = [];
        foreach ($buffer as $frame) $this->apply($frame);
        $this->stale = false;
    }
    public function event(array $e): void
    {
        if (in_array($e['Event'] ?? '', ['CoreShowChannel','CoreShowChannelsComplete','QueueStatusComplete'], true)) return;
        // QueueStatus frames belong to the snapshot, not to the live replay.
        if (isset($e['ActionID']) && in_array($e['Event'] ?? '', ['QueueEntry','QueueParams','QueueMember'], true)) return;
        if ($this->syncing) {
            if (count($this->buffer) >= 20000) { $this->disconnect(); return; }
            $e['_received'] = microtime(true);
            $this->buffer[] = $e;
        } else $this->apply($e);
    }
    private function apply(array $e): void
    {
        $type = $e['Event'] ?? '';
        $id = $e['Uniqueid'] ?? '';
        $now = $e['_received'] ?? microtime(true);
        if (in_array($type, ['Newchannel','Newstate','NewCallerid','NewConnectedLine','Newexten','VarSet'], true) && $id) {
            $previous = $this->channels[$id] ?? ['_created'=>$now];
            $this->channels[$id] = array_replace($previous, $e);
            if (($e['ChannelStateDesc'] ?? '') === 'Up' && ($previous['ChannelStateDesc'] ?? '') !== 'Up' && ($previous['BridgeId'] ?? '') !== '') $this->channels[$id]['_connected'] = $now;
        } elseif ($type === 'Hangup') {
            $channel = $e['Channel'] ?? ($this->channels[$id]['Channel'] ?? '');
            unset($this->channels[$id]);
            foreach ($this->waiting as &$entries) unset($entries[$channel]);
            unset($entries);
        } elseif ($type === 'BridgeEnter' && $id) {
            $this->channels[$id] = array_replace($this->channels[$id] ?? ['_created'=>$now], $e);
            $this->channels[$id]['BridgeId'] = $e['BridgeUniqueid'] ?? '';
            $this->channels[$id]['_connected'] ??= $now;
        } elseif ($type === 'BridgeLeave' && isset($this->channels[$id])) {
            $this->channels[$id]['BridgeId'] = '';
            unset($this->channels[$id]['_connected']);
        } elseif ($type === 'BridgeDestroy') {
            foreach ($this->channels as &$channel) if (($channel['BridgeId'] ?? '') === ($e['BridgeUniqueid'] ?? '')) { $channel['BridgeId'] = ''; unset($channel['_connected']); }
            unset($channel);
        } elseif ($type === 'Rename' && isset($this->channels[$id])) {
            $this->channels[$id]['Channel'] = $e['Newname'];
            foreach ($this->waiting as &$entries) if (isset($entries[$e['Channel']])) { $entries[$e['Newname']] = $entries[$e['Channel']]; unset($entries[$e['Channel']]); }
            unset($entries);
        } elseif (in_array($type, ['DialBegin','DialEnd'], true)) {
            $dest = $e['DestUniqueid'] ?? '';
            if ($dest && $type === 'DialBegin') {
                $fields = ['Uniqueid'=>$dest,'Channel'=>$e['DestChannel'] ?? '', 'Linkedid'=>$e['DestLinkedid'] ?? ($e['Linkedid'] ?? $id),'ChannelStateDesc'=>'Ringing','_created'=>$now];
                foreach (['CallerIDNum','ConnectedLineNum','CallerIDName'] as $key) if (isset($e['Dest'.$key])) $fields[$key] = $e['Dest'.$key];
                $this->channels[$dest] = array_replace($fields, $this->channels[$dest] ?? []);
            }
            if ($id && isset($this->channels[$id]) && $type === 'DialBegin') $this->channels[$id]['_dial_dest'] = $dest;
        } elseif (in_array($type, ['QueueEntry','QueueCallerJoin'], true)) {
            $q = $e['Queue'] ?? ''; $channel = $e['Channel'] ?? '';
            if ($q && $channel) $this->waiting[$q][$channel] = array_replace($e, ['_since'=>$now - max(0, (int)($e['Wait'] ?? 0))]);
        } elseif (in_array($type, ['QueueCallerLeave','QueueCallerAbandon','AgentConnect'], true)) {
            unset($this->waiting[$e['Queue'] ?? ''][$e['Channel'] ?? '']);
        } elseif (in_array($type, ['QueueMember','QueueMemberStatus','QueueMemberAdded','QueueMemberPause'], true)) {
            $q = $e['Queue'] ?? ''; $interface = $e['Interface'] ?? $e['Location'] ?? '';
            if ($q && $interface) $this->members[$q][$interface] = array_replace($this->members[$q][$interface] ?? [], $e);
        } elseif ($type === 'QueueMemberRemoved') unset($this->members[$e['Queue'] ?? ''][$e['Interface'] ?? '']);
    }
    public function channelExists(string $id, string $channel): bool { return ($this->channels[$id]['Channel'] ?? '') === $channel; }
    public function bridged(string $one, string $two): bool
    {
        if (!isset($this->channels[$one], $this->channels[$two])) return false;
        $pending = [$one]; $seen = [];
        while ($pending) {
            $id = array_pop($pending);
            if (isset($seen[$id]) || ($this->channels[$id]['ChannelStateDesc'] ?? '') !== 'Up') continue;
            $seen[$id] = true;
            if ($id === $two) return true;
            $c = $this->channels[$id]; $bridge = $c['BridgeId'] ?? '';
            $local = str_starts_with($c['Channel'] ?? '', 'Local/') ? explode(';', $c['Channel'])[0] : '';
            foreach ($this->channels as $peer => $p) {
                if (($bridge !== '' && $bridge === ($p['BridgeId'] ?? ''))
                    || ($local !== '' && $local === explode(';', $p['Channel'] ?? '')[0])) $pending[] = (string)$peer;
            }
        }
        return false;
    }
    private function extension(string $channel): string
    {
        if (!preg_match('~^(?:PJSIP|SIP|Local)/([^/@;]+)~i', $channel, $m)) return '';
        $endpoint = preg_replace('/-[0-9a-f]+$/i', '', $m[1]);
        return isset($this->config['extensions'][$endpoint]) ? $endpoint : '';
    }
    public function view(): array
    {
        $now = microtime(true);
        $result = ['generated_at'=>gmdate('c'), 'ami_connected'=>$this->online, 'stale'=>$this->stale, 'calls'=>[], 'queues'=>[], 'extensions'=>[]];
        if (!$this->stale) {
            $channels = $this->channels;
            foreach ($this->waiting as $entries) foreach ($entries as $channel => $entry) {
                $found = false;
                foreach ($channels as $c) if (($c['Channel'] ?? '') === $channel) { $found = true; break; }
                if (!$found) { $id = $entry['Uniqueid'] ?? 'queue:' . $channel; $channels[$id] = $entry + ['Uniqueid'=>$id, 'Linkedid'=>$entry['Linkedid'] ?? $id, '_created'=>$entry['_since']]; }
            }
            // Bridge components are authoritative; Linkedid only joins groups without distinct bridges.
            $parents = array_combine(array_keys($channels), array_keys($channels));
            $find = function (string $id) use (&$parents, &$find): string { return $parents[$id] === $id ? $id : ($parents[$id] = $find($parents[$id])); };
            $union = function (string $a, string $b) use (&$parents, $find): void { $parents[$find($b)] = $find($a); };
            $bridges = []; $locals = []; $linked = [];
            foreach ($channels as $id => $c) {
                $b = $c['BridgeId'] ?? '';
                if ($b) { if (isset($bridges[$b])) $union($id, $bridges[$b]); else $bridges[$b] = $id; }
                $name = $c['Channel'] ?? '';
                if (str_starts_with($name, 'Local/')) { $base = explode(';', $name)[0]; if (isset($locals[$base])) $union($id, $locals[$base]); else $locals[$base] = $id; }
                $linked[$c['Linkedid'] ?? $id][] = $id;
            }
            foreach ($linked as $ids) {
                $bridgedRoots = [];
                foreach ($ids as $id) if (($channels[$id]['BridgeId'] ?? '') !== '') $bridgedRoots[$find($id)] = true;
                if (count($bridgedRoots) <= 1) foreach ($ids as $id) $union($ids[0], $id);
            }
            $groups = [];
            foreach ($channels as $id => $c) $groups[$find($id)][$id] = $c;
            $usedIds = []; $channelCall = [];
            foreach ($groups as $group) {
                $first = reset($group); $linkedId = $first['Linkedid'] ?? $first['Uniqueid'];
                $callId = (string)$linkedId;
                if (isset($usedIds[$callId])) $callId .= ':' . $first['Uniqueid'];
                $usedIds[$callId] = true;
                $ext = []; $number = ''; $numberScore = -1; $active = false; $ringing = false; $direction = 'unknown'; $created = $now; $connected = null;
                foreach ($group as $id => $c) {
                    $channel = $c['Channel'] ?? ''; $channelCall[$channel] = $callId;
                    $extension = $this->extension($channel);
                    if ($extension !== '') $ext[$extension] = true;
                    $bridge = $c['BridgeId'] ?? '';
                    if ($extension !== '' && !str_starts_with($channel, 'Local/') && $bridge !== '' && ($c['ChannelStateDesc'] ?? '') === 'Up') {
                        foreach ($group as $peerId => $peer) {
                            if ($peerId === $id || str_starts_with($peer['Channel'] ?? '', 'Local/') || !$this->bridged((string)$id, (string)$peerId)) continue;
                            $active = true;
                            $started = max($c['_connected'] ?? 0, $peer['_connected'] ?? 0);
                            if ($started > 0) $connected = $connected === null ? $started : min($connected, $started);
                        }
                    }
                    $ringing = $ringing || in_array($c['ChannelStateDesc'] ?? '', ['Ring','Ringing'], true);
                    $created = min($created, $c['_created'] ?? $now);
                    foreach (['CallerIDNum','ConnectedLineNum'] as $field) {
                        $candidate = Phone::external((string)($c[$field] ?? ''), $this->config['extensions']);
                        if ($candidate === '') continue;
                        $score = $extension !== '' ? ($field === 'ConnectedLineNum' ? 5 : 3) : ($field === 'CallerIDNum' ? 4 : 1);
                        if (str_starts_with($channel, 'Local/')) $score -= 2;
                        if ($score > $numberScore) { $number = $candidate; $numberScore = $score; }
                    }
                    if ($id === $linkedId) $direction = $extension !== '' ? 'outgoing' : 'incoming';
                }
                $result['calls'][] = ['id'=>$callId,'direction'=>$direction,'state'=>$active ? 'connected' : ($ringing ? 'ringing' : 'dialing'), 'number'=>$number, 'extensions'=>array_map('strval', array_keys($ext)), 'channel_age_seconds'=>max(0, (int)($now - $created)), 'duration_seconds'=>$active && $connected !== null ? max(0, (int)($now - $connected)) : null, 'name_status'=>$number ? 'pending' : 'not_found', 'patients'=>[]];
            }
            $queueNames = array_unique(array_merge($this->config['queues'], array_keys($this->waiting), array_keys($this->members)));
            foreach ($queueNames as $q) {
                if ($this->config['queues'] && !in_array($q, $this->config['queues'], true)) continue;
                $entries = [];
                foreach ($this->waiting[$q] ?? [] as $channel => $entry) {
                    $callId = $channelCall[$channel] ?? null;
                    if ($callId === null) continue;
                    $entries[] = ['call_id'=>$callId, 'position'=>(int)($entry['Position'] ?? 0), 'wait_seconds'=>max(0,(int)($now - $entry['_since']))];
                    foreach ($result['calls'] as &$call) if ($call['id'] === $callId) { $call['state'] = 'waiting'; $call['direction'] = 'incoming'; }
                    unset($call);
                }
                usort($entries, fn($a,$b) => $a['position'] <=> $b['position']);
                foreach ($entries as $i => &$entry) $entry['position'] = $i + 1;
                unset($entry);
                $result['queues'][] = ['name'=>$q, 'waiting_count'=>count($entries), 'entries'=>$entries];
            }
        }
        foreach ($this->config['extensions'] as $id => $label) {
            $state = $this->stale ? 'unknown' : 'idle';
            $paused = false;
            foreach ($this->members as $members) foreach ($members as $interface => $member) if ($this->extension($interface) === (string)$id) {
                if (in_array((string)($member['Status'] ?? ''), ['4','5'], true)) $state = 'offline';
                if (in_array((string)($member['Paused'] ?? ''), ['1','true'], true)) $paused = true;
            }
            foreach ($result['calls'] as $call) if (in_array((string)$id, $call['extensions'], true)) $state = $call['state'] === 'connected' ? 'busy' : 'ringing';
            $result['extensions'][] = ['id'=>(string)$id,'label'=>$label,'state'=>$state,'paused'=>$paused];
        }
        return $result;
    }
    private static function duration(string $value): int
    {
        if (ctype_digit($value)) return (int)$value;
        if (preg_match('/^(\d+):(\d\d):(\d\d)$/D', $value, $m)) return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        return 0;
    }
}
