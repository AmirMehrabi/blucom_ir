<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['plan_id', 'version', 'features', 'limits', 'limit_scope', 'billing_interval', 'published_at'])]
class PlanVersion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['features' => 'array', 'limits' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('published_at') !== null && $version->isDirty()) {
                throw ValidationException::withMessages(['plan' => 'نسخه منتشرشده تغییر نمی‌کند؛ نسخه جدید بسازید.']);
            }
        });
        static::deleting(function (self $version): void {
            if ($version->published_at !== null) {
                throw ValidationException::withMessages(['plan' => 'نسخه منتشرشده قابل حذف نیست.']);
            }
        });
    }
}
