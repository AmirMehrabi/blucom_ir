<?php

namespace App\Services;

use App\Models\CallRecord;

/** Conservative reporting classification; never grants routing authorization. */
class CallOutcomeClassifier
{
    public const VERSION = 1;

    public const LABELS = [
        'answered' => 'متصل‌شده', 'no_answer' => 'بدون پاسخ', 'abandoned' => 'قطع‌شده توسط تماس‌گیرنده',
        'busy' => 'مشغول', 'unreachable' => 'خارج از دسترس', 'failed' => 'خطای فنی',
        'announcement_played' => 'اعلان پخش شد', 'voicemail_entered' => 'ورود به صندوق صوتی',
        'voicemail_saved' => 'پیام صوتی ذخیره شد',
        'system_only' => 'فقط پاسخ سامانه', 'unknown' => 'نتیجه نامشخص',
    ];

    public const ANSWER_LABELS = [
        'bridge_connected' => 'اتصال تأییدشده به مقصد',
        'system_answer_only' => 'پاسخ سامانه؛ اتصال به مقصد تأیید نشده',
        'legacy_answer_unverified' => 'پاسخ ثبت‌شده؛ نوع پاسخ نامشخص',
        'none' => 'بدون اتصال تأییدشده',
    ];

    private const CAUSES = [
        'busy' => ['USER_BUSY'],
        'no_answer' => ['NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT'],
        'abandoned' => ['ORIGINATOR_CANCEL'],
        'unreachable' => ['USER_NOT_REGISTERED', 'SUBSCRIBER_ABSENT', 'NO_ROUTE_DESTINATION', 'UNALLOCATED_NUMBER'],
        'failed' => ['NORMAL_TEMPORARY_FAILURE', 'NETWORK_OUT_OF_ORDER', 'DESTINATION_OUT_OF_ORDER', 'GATEWAY_DOWN', 'RECOVERY_ON_TIMER_EXPIRE', 'INCOMPATIBLE_DESTINATION', 'SERVICE_UNAVAILABLE', 'SWITCH_CONGESTION', 'NORMAL_CIRCUIT_CONGESTION', 'PROTOCOL_ERROR'],
    ];

    public function classify(array $facts): array
    {
        $disposition = strtoupper(trim((string) ($facts['originate_disposition'] ?? '')));
        $queue = $facts['queue_outcome'] ?? null;
        $connected = ($facts['bridge_confirmed'] ?? false) || $queue === 'answered'
            || in_array($disposition, ['SUCCESS', 'CALL ACCEPTED'], true);
        if ($connected) {
            return $this->result('answered', 'bridge_connected');
        }
        if (in_array($facts['audio_outcome'] ?? null, ['announcement_played', 'voicemail_entered', 'voicemail_saved'], true)) {
            return $this->result($facts['audio_outcome'], 'system_answer_only');
        }
        $cause = isset($facts['originate_disposition']) && $disposition !== '' && ! in_array($disposition, ['FAILURE', 'NONE'], true)
            ? $disposition : strtoupper((string) ($facts['hangup_cause'] ?? ''));
        foreach (self::CAUSES as $outcome => $causes) {
            if (in_array($cause, $causes, true)) {
                return $this->result($outcome, ! empty($facts['answered_at']) && ! empty($facts['ivr_menu_id']) ? 'system_answer_only' : 'none');
            }
        }
        if (! empty($facts['answered_at']) && ! empty($facts['ivr_menu_id']) && $queue === null && $disposition === '') {
            return $this->result('system_only', 'system_answer_only');
        }

        // A-leg answer stamps can include IVR/voicemail. Never infer a person from them.
        return $this->result('unknown', ! empty($facts['answered_at']) ? 'legacy_answer_unverified' : 'none');
    }

    private function result(string $outcome, string $answer): array
    {
        return ['outcome' => $outcome, 'answer_evidence' => $answer, 'outcome_version' => self::VERSION];
    }

    /** Identical legacy fallback in SQL and model accessors; no historical answer guess. */
    public static function expression(): string
    {
        $sql = 'CASE';
        foreach (self::CAUSES as $outcome => $causes) {
            $quoted = implode(',', array_map(fn ($cause) => "'{$cause}'", $causes));
            $sql .= " WHEN UPPER(hangup_cause) IN ({$quoted}) AND answered_at IS NULL THEN '{$outcome}'";
        }

        return "COALESCE(outcome, {$sql} ELSE 'unknown' END)";
    }

    public function forRecord(CallRecord $call): string
    {
        if ($call->outcome !== null) {
            return $call->outcome;
        }
        if ($call->answered_at === null) {
            foreach (self::CAUSES as $outcome => $causes) {
                if (in_array(strtoupper($call->hangup_cause ?? ''), $causes, true)) {
                    return $outcome;
                }
            }
        }

        return 'unknown';
    }
}
