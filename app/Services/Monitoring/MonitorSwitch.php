<?php

namespace App\Services\Monitoring;

use App\Models\CallMonitorSession;
use App\Models\SipExtension;
use App\Services\FreeSwitch\EventSocket;
use App\Services\FreeSwitch\LiveStateProjector;
use Illuminate\Support\Str;
use RuntimeException;

/** Only typed operations; no HTTP-supplied dial strings or commands. */
class MonitorSwitch
{
    public function __construct(private EventSocket $socket) {}

    public function using(callable $callback): mixed
    {
        try {
            $this->socket->connect();

            return $callback($this);
        } finally {
            $this->socket->close();
        }
    }

    public function assertTarget(SipExtension $extension, string $uuid): void
    {
        $this->uuid($uuid);
        $dump = app(LiveStateProjector::class)->dump($this->socket->api('uuid_dump '.$uuid));
        $domain = (string) config('voip.directory_domain');
        $outgoing = ($dump['Caller-Direction'] ?? '') === 'outbound';
        $identity = $outgoing ? ($dump['variable_dialed_user'] ?? '') : ($dump['variable_sip_auth_username'] ?? '');
        $realm = $outgoing ? ($dump['variable_dialed_domain'] ?? '') : ($dump['variable_sip_auth_realm'] ?? '');
        abort_unless(str_starts_with($dump['Channel-Name'] ?? '', 'sofia/'.config('voip.live.profile').'/')
            && $identity === $extension->sipIdentity() && $realm === $domain
            && (int) ($dump['Caller-Channel-Answered-Time'] ?? 0) > 0
            && Str::isUuid($dump['variable_bridge_uuid'] ?? '')
            && ! isset($dump['variable_blucom_monitor_id']), 409, 'تماس پایان یافته یا برای شنود در دسترس نیست.');
    }

    public function originate(CallMonitorSession $session, SipExtension $target, ?SipExtension $supervisor): void
    {
        $identity = $session->mode === 'web' ? $session->sip_identity : $supervisor?->sipIdentity();
        $domain = (string) config('voip.directory_domain');
        $profile = (string) config('voip.live.profile');
        foreach ([$identity, $domain, $profile, $target->sipIdentity()] as $value) {
            if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $value)) {
                throw new RuntimeException('Invalid monitoring configuration.');
            }
        }
        $this->uuid($session->id);
        $this->uuid($session->listener_uuid);
        $this->uuid($session->target_uuid);
        $seconds = max(1, $session->expires_at->timestamp - now()->timestamp);
        $vars = 'origination_uuid='.$session->listener_uuid.',originate_timeout=25,ignore_early_media=true'.
            ',origination_caller_id_name=Blucom-Monitor,origination_caller_id_number='.$session->id.
            ',blucom_monitor_id='.$session->id.',hangup_after_bridge=true';
        // The native script repeats identity checks after the supervisor answers.
        $args = implode(' ', [$session->target_uuid, $target->sipIdentity(), $domain, $profile, $seconds, $session->mode]);
        $this->socket->api('hash insert/monitor-session/'.$session->listener_uuid.'/'.$session->target_uuid);
        $this->socket->background('originate {'.$vars.'}user/'.$identity.'@'.$domain." '&lua(blucom_monitor.lua ".$args.")'", (string) Str::uuid());
    }

    public function status(CallMonitorSession $session): string
    {
        $this->uuid($session->listener_uuid);
        if (trim($this->socket->api('uuid_exists '.$session->listener_uuid)) !== 'true') {
            return 'absent';
        }

        return trim($this->socket->api('uuid_getvar '.$session->listener_uuid.' blucom_monitor_started')) === 'true' ? 'listening' : 'ringing';
    }

    public function heartbeat(CallMonitorSession $session): void
    {
        $this->uuid($session->listener_uuid);
        // Add the replacement before deleting the previous task: an outage fails closed.
        $reply = trim($this->socket->api('sched_api +45 monitor-'.$session->listener_uuid.' uuid_kill '.$session->listener_uuid.' ALLOTTED_TIMEOUT'));
        if (! preg_match('/^\+OK Added: (\d+)$/D', $reply, $matches)) {
            throw new RuntimeException('Cannot renew monitoring lease.');
        }
        $old = cache()->pull('monitor-task:'.$session->id);
        cache()->put('monitor-task:'.$session->id, $matches[1], 120);
        if (is_string($old) && ctype_digit($old)) {
            $this->socket->api('sched_del '.$old);
        }
    }

    public function stop(CallMonitorSession $session): void
    {
        $this->uuid($session->listener_uuid);
        $this->socket->api('hash delete/monitor-session/'.$session->listener_uuid);
        $this->socket->api('uuid_kill '.$session->listener_uuid.' ORIGINATOR_CANCEL');
    }

    private function uuid(string $uuid): void
    {
        if (! Str::isUuid($uuid)) {
            throw new RuntimeException('Invalid monitoring identifier.');
        }
    }
}
