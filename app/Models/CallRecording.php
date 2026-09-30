<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallRecording extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['policy' => 'array', 'expires_at' => 'datetime', 'deleted_at' => 'datetime',
            'bytes' => 'integer', 'reserved_bytes' => 'integer', 'duration_seconds' => 'integer'];
    }

    public function callRecord(): BelongsTo
    {
        return $this->belongsTo(CallRecord::class);
    }

    public function sipNumber(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'recording' => 'در حال ضبط', 'processing' => 'در حال آماده‌سازی',
            'ready' => 'آماده پخش', 'failed' => 'ضبط ناموفق',
            'skipped' => 'ضبط نشد؛ فضای ناکافی', 'empty' => 'بدون مکالمه ضبط‌شده', 'expired' => 'منقضی‌شده',
            'deleting' => 'در حال حذف', 'deleted' => 'حذف‌شده', default => $this->status,
        };
    }
}
