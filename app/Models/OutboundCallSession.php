<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboundCallSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['status' => 'created', 'dispatch_status' => 'pending', 'conversation_seconds' => 0];

    protected $hidden = ['configuration', 'request_hash', 'agent_uuid', 'customer_uuid', 'job_uuid'];

    public const TERMINAL = ['completed', 'failed', 'cancelled'];

    public const STAGES = ['created', 'agent_ringing', 'agent_answered', 'agent_prompt', 'agent_confirmation', 'customer_dialing', 'customer_ringing', 'customer_answered', 'customer_prompt', 'bridged'];

    public const LABELS = [
        'created' => 'در انتظار شروع', 'agent_ringing' => 'زنگ داخلی', 'agent_answered' => 'پاسخ داخلی',
        'agent_prompt' => 'پخش پیام داخلی', 'agent_confirmation' => 'در انتظار تأیید با کلید ۱',
        'customer_dialing' => 'شماره‌گیری مشتری', 'customer_ringing' => 'زنگ مشتری',
        'customer_answered' => 'پاسخ مشتری', 'customer_prompt' => 'پخش پیام مشتری',
        'bridged' => 'در حال مکالمه', 'completed' => 'پایان مکالمه', 'failed' => 'تماس ناموفق', 'cancelled' => 'لغوشده',
    ];

    public const FAILURES = [
        'agent_busy' => 'داخلی مشغول است', 'agent_unanswered' => 'داخلی پاسخ نداد', 'agent_unavailable' => 'داخلی در دسترس نیست',
        'agent_declined_confirmation' => 'تأیید داخلی دریافت نشد', 'agent_hangup' => 'داخلی تماس را قطع کرد',
        'customer_busy' => 'مشتری مشغول است', 'customer_unanswered' => 'مشتری پاسخ نداد',
        'customer_unreachable' => 'مشتری در دسترس نیست', 'trunk_failure' => 'خطای اتصال ارائه‌دهنده',
        'authorization_failure' => 'مجوز تماس معتبر نیست', 'call_cancelled' => 'تماس لغو شد',
        'control_unavailable' => 'ارتباط کنترل تماس قطع شد', 'workflow_timeout' => 'مهلت تماس پایان یافت',
        'playback_failed' => 'پخش پیام صوتی ناموفق بود',
    ];

    protected function casts(): array
    {
        $casts = ['configuration' => 'array'];
        foreach (['dispatch_started_at', 'agent_answered_at', 'agent_ready_at', 'customer_dialed_at', 'customer_answered_at', 'bridged_at', 'ended_at', 'cancel_requested_at', 'cleanup_completed_at', 'deadline_at'] as $field) {
            $casts[$field] = 'immutable_datetime';
        }

        return $casts;
    }

    public function terminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function events()
    {
        return $this->hasMany(OutboundCallEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function recording()
    {
        return $this->hasOne(CallRecording::class);
    }

    public function extension()
    {
        return $this->belongsTo(SipExtension::class, 'sip_extension_id');
    }

    public function number()
    {
        return $this->belongsTo(SipNumber::class, 'sip_number_id');
    }
}
