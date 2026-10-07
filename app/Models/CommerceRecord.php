<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Financial identities and issued snapshots are retained; only explicit lifecycle fields may change. */
abstract class CommerceRecord extends Model
{
    protected $guarded = [];

    protected array $mutableFields = [];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), [...$record->mutableFields, 'updated_at']) !== []) {
                throw ValidationException::withMessages(['commerce' => 'هویت و اطلاعات ثبت‌شده مالی قابل تغییر نیست.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['commerce' => 'تاریخچه مالی قابل حذف نیست.']));
    }
}
