<?php

namespace App\Services\FreeSwitch;

use Illuminate\Support\Collection;

/** Projects only authenticated/local phone legs; provider caller IDs are never identities. */
class LiveStateProjector
{
    public function project(Collection $extensions, array $registrations, array $channels, array $dumps, int $now): array
    {
        $domain = (string) config('voip.directory_domain');
        $profile = (string) config('voip.live.profile');
        // A shared Sofia domain cannot safely identify duplicated extension numbers.
        $identities = $extensions->groupBy('extension')->filter(fn ($group) => $group->count() === 1)->map->first();
        $state = [];
        foreach ($extensions as $extension) {
            $state[$extension->id] = ['registered' => false, 'devices' => 0, 'calls' => []];
        }
        foreach ($registrations as $registration) {
            $extension = $identities->get($registration['reg_user'] ?? '');
            if ($extension === null || ($registration['realm'] ?? '') !== $domain
                || ! str_starts_with($registration['url'] ?? '', 'sofia/'.$profile.'/')
                || (int) ($registration['expires'] ?? 0) <= $now) {
                continue;
            }
            $state[$extension->id]['registered'] = true;
            $state[$extension->id]['devices']++;
        }
        $byUuid = collect($channels)->keyBy('uuid');
        foreach ($channels as $channel) {
            if (! str_starts_with($channel['name'] ?? '', 'sofia/'.$profile.'/')) {
                continue;
            }
            $uuid = $channel['uuid'] ?? '';
            $dump = $dumps[$uuid] ?? [];
            $outgoing = ($channel['direction'] ?? '') === 'outbound';
            if ($outgoing) {
                $user = $dump['variable_dialed_user'] ?? $dump['variable_sip_to_user'] ?? '';
                $realm = $dump['variable_dialed_domain'] ?? $dump['variable_sip_to_host'] ?? '';
            } else {
                $user = $dump['variable_sip_auth_username'] ?? '';
                $realm = $dump['variable_sip_auth_realm'] ?? $dump['variable_domain_name'] ?? '';
            }
            $extension = $identities->get($user);
            if ($extension === null || $realm !== $domain) {
                continue;
            }
            $callstate = strtoupper($channel['callstate'] ?? '');
            if (in_array($callstate, ['HANGUP', 'DOWN'], true) || ($channel['state'] ?? '') === 'CS_HANGUP') {
                continue;
            }
            $peerUuid = $dump['variable_bridge_uuid'] ?? $dump['Other-Leg-Unique-ID'] ?? '';
            $peer = $byUuid->get($peerUuid);
            $connected = $peerUuid !== '' && $peer !== null;
            $status = match (true) {
                $callstate === 'HELD' => 'hold',
                $callstate === 'ACTIVE' && $connected => 'talking',
                $callstate === 'ACTIVE' => 'connecting',
                $outgoing => 'ringing',
                default => 'dialing',
            };
            $internal = $peer !== null && str_starts_with($peer['name'] ?? '', 'sofia/'.$profile.'/');
            $localDestination = $identities->get($channel['dest'] ?? '');
            if (! $outgoing && $localDestination?->tenant_id === $extension->tenant_id) {
                $internal = true;
            }
            $answered = (int) ($dump['Caller-Channel-Answered-Time'] ?? 0);
            $started = $answered > 0 && in_array($status, ['talking', 'hold'], true)
                ? (int) floor($answered / 1000000) : (int) ($channel['created_epoch'] ?? $now);
            $state[$extension->id]['calls'][] = [
                'id' => $uuid,
                'conversation_id' => $connected ? min($uuid, $peerUuid) : $uuid,
                'state' => $status,
                'direction' => $internal ? 'internal' : ($outgoing ? 'inbound' : 'outbound'),
                'number' => substr((string) ($outgoing ? ($channel['cid_num'] ?? '') : ($channel['dest'] ?? '')), 0, 40),
                'started_at' => min($now, $started),
            ];
        }

        return $state;
    }

    public function dump(string $body): array
    {
        $result = [];
        foreach (explode("\n", trim($body)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $result[$key] = rawurldecode(trim($value));
            }
        }

        return $result;
    }
}
