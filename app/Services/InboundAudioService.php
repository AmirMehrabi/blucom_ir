<?php

namespace App\Services;

use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InboundAudioService
{
    /** @return Collection<int, TenantAudioAsset> */
    public function assets(Tenant $tenant): Collection
    {
        return TenantAudioAsset::query()->where('tenant_id', $tenant->id)
            ->whereNull('archived_at')->where('storage_key', 'like', 'inbound/'.$tenant->id.'/%')->orderBy('name')->get();
    }

    public function configure(int $tenantId, string $choice, string $field): void
    {
        if (preg_match('/^voicemail:([1-9][0-9]*)$/D', $choice, $matches) && config('voip.voicemail_enabled')) {
            $exists = DB::transaction(function () use ($tenantId, $matches): bool {
                $mailbox = SipExtension::query()->where('tenant_id', $tenantId)->where('enabled', true)
                    ->lockForUpdate()->find($matches[1]);
                if (! $mailbox) {
                    return false;
                }
                if (! $mailbox->voicemail_secret_encrypted) {
                    $mailbox->update(['voicemail_secret_encrypted' => (string) random_int(100000000000, 999999999999)]);
                }

                return true;
            });
            if ($exists) {
                return;
            }
        }

        if ($this->announcement($tenantId, $choice)) {
            return;
        }

        throw ValidationException::withMessages([$field => 'پیام یا صندوق صوتی فعال همین مجموعه را انتخاب کنید.']);
    }

    public function announcement(int $tenantId, string $choice): ?string
    {
        if (! preg_match('/^announcement:([a-f0-9-]{36})$/D', $choice, $matches)) {
            return null;
        }
        $asset = TenantAudioAsset::query()->where('tenant_id', $tenantId)->find($matches[1]);
        $key = 'inbound/'.$tenantId.'/'.$matches[1].'.wav';
        if (! $asset || $asset->archived_at !== null || $asset->storage_key !== $key) {
            return null;
        }
        $path = Storage::disk('ivr')->path($key);

        return is_file($path) && ! is_link($path) && preg_match('#^/[a-zA-Z0-9_./-]+$#D', $path) ? $path : null;
    }

    public function mailbox(int $tenantId, string $choice): ?SipExtension
    {
        if (! config('voip.voicemail_enabled') || ! preg_match('/^voicemail:([1-9][0-9]*)$/D', $choice, $matches)) {
            return null;
        }

        return SipExtension::query()->where('tenant_id', $tenantId)->where('enabled', true)
            ->whereNotNull('voicemail_secret_encrypted')->find($matches[1]);
    }

    public static function isAudio(?string $choice): bool
    {
        return str_starts_with($choice ?? '', 'announcement:') || str_starts_with($choice ?? '', 'voicemail:');
    }
}
