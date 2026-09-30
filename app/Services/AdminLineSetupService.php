<?php

namespace App\Services;

use App\Models\AdminLineSetup;
use App\Models\OutboundRoute;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminLineSetupService
{
    public function gateways(Tenant $tenant)
    {
        return SipGateway::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere(fn ($q) => $q
                ->where('tenant_id', $tenant->id)->where('verification_status', SipGateway::STATUS_APPROVED)));
    }

    public function fingerprint(SipNumber $number): string
    {
        return hash('sha256', json_encode([
            $number->fresh()->getAttributes(),
            $number->inboundRoute()->first()?->getAttributes(),
            $number->outboundRoutes()->orderBy('id')->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function outboundSnapshot(Tenant $tenant, array $ids): array
    {
        return OutboundRoute::query()->whereBelongsTo($tenant)->whereIn('sip_extension_id', $ids)
            ->orderBy('sip_extension_id')->get()->map->getAttributes()->all();
    }

    public function finish(AdminLineSetup $setup): array
    {
        $newAudio = null;
        try {
            $result = DB::transaction(function () use ($setup, &$newAudio): array {
                $draft = AdminLineSetup::query()->lockForUpdate()->findOrFail($setup->id);
                if ($draft->completed_at !== null) {
                    return ['number' => SipNumber::query()->findOrFail($draft->sip_number_id), 'credentials' => null];
                }
                abort_unless($draft->step === 6, 422);
                $tenant = Tenant::query()->where('status', 'active')->findOrFail($draft->tenant_id);
                $data = $draft->data;
                $gateway = $this->gateways($tenant)->find($data['gateway_id']);
                if ($gateway === null) {
                    throw ValidationException::withMessages(['gateway_id' => 'اتصال دیگر فعال یا مجاز نیست. مرحله اتصال را اصلاح کنید.']);
                }
                if (($data['number_mode'] ?? '') === 'existing') {
                    $number = $tenant->sipNumbers()->lockForUpdate()->findOrFail($data['number_id']);
                    if ($this->fingerprint($number) !== $data['number_fingerprint']) {
                        throw ValidationException::withMessages(['number_id' => 'تنظیمات شماره از زمان انتخاب تغییر کرده است. مرحله شماره را دوباره ذخیره و تغییرات را بررسی کنید.']);
                    }
                    if ($number->outboundRoutes()->where('gateway_id', '!=', $gateway->id)
                        ->whereNotIn('sip_extension_id', $data['outbound_extension_ids'] ?? [])->exists()) {
                        throw ValidationException::withMessages(['gateway_id' => 'این شماره مسیر خروجی دیگری دارد. همه داخلی‌های وابسته را در مرحله خروجی انتخاب کنید یا ابتدا مسیرهای قدیمی را اصلاح کنید.']);
                    }
                } else {
                    $normalized = app(NumberNormalizer::class)->normalizeOrFail($data['number']);
                    if (SipNumber::query()->where('normalized_number', $normalized)->exists()) {
                        throw ValidationException::withMessages(['number' => 'این شماره قبلاً ثبت شده است.']);
                    }
                    $number = SipNumber::query()->create([
                        'tenant_id' => $tenant->id, 'number' => $data['number'], 'normalized_number' => $normalized,
                        'label' => $data['label'] ?? null, 'status' => SipNumber::STATUS_ASSIGNED,
                        'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => false,
                    ]);
                }
                $ids = $data['outbound_extension_ids'] ?? [];
                if ($this->outboundSnapshot($tenant, $ids) !== ($data['outbound_snapshot'] ?? [])) {
                    throw ValidationException::withMessages(['outbound_extension_ids' => 'مسیر خروجی یکی از داخلی‌ها تغییر کرده است. مرحله خروجی را دوباره بررسی کنید.']);
                }
                $number->update(['provider_gateway_id' => $gateway->id, 'label' => $data['label'] ?? $number->label]);
                $credentials = null;
                if ($data['answerer'] === 'new') {
                    $phone = app(CustomerLineSetupService::class)->createPhone($tenant, $data['display_name']);
                    $data['destination_choice'] = 'extension:'.$phone['extension']->id;
                    $credentials = ['extension' => $phone['extension']->extension, 'password' => $phone['password'],
                        'host' => config('voip.sip_host'), 'port' => config('voip.sip_port')];
                    if ($data['outbound_enabled'] && ($data['include_new_extension'] ?? false)) {
                        $ids[] = $phone['extension']->id;
                    }
                }
                if (($data['schedule_mode'] ?? '') === 'scheduled' && ($data['closed_action'] ?? '') === 'announcement' && $draft->announcement_upload) {
                    abort_unless(preg_match('#^wizard-uploads/'.$draft->id.'/[0-9A-Z]+\.upload$#D', $draft->announcement_upload)
                        && Storage::disk('ivr')->exists($draft->announcement_upload), 422);
                    $upload = new UploadedFile(Storage::disk('ivr')->path($draft->announcement_upload), 'announcement.wav', null, null, true);
                    $data['announcement_path'] = $newAudio = app(InboundAnnouncementService::class)->store($number, $upload);
                }
                app(InboundRoutingService::class)->configure($tenant, $number, $data + ['enabled' => true]);
                if ($data['outbound_enabled']) {
                    if (! $gateway->approved_for_outbound || ($ids === [])) {
                        throw ValidationException::withMessages(['outbound_extension_ids' => 'برای خروجی، اتصال مجاز و دست‌کم یک داخلی انتخاب کنید.']);
                    }
                    foreach (array_unique($ids) as $id) {
                        $extension = $tenant->sipExtensions()->where('enabled', true)->findOrFail($id);
                        OutboundRoute::query()->updateOrCreate(['sip_extension_id' => $extension->id], [
                            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'gateway_id' => $gateway->id, 'enabled' => true,
                        ]);
                    }
                }
                // Skipping outbound leaves existing outbound assignments intact.
                $number->update(['outbound_enabled' => $data['outbound_enabled'] ? true : $number->outbound_enabled]);
                $draft->update(['completed_at' => now(), 'sip_number_id' => $number->id]);
                Log::info('Admin line setup completed', ['setup_id' => $draft->id, 'tenant_id' => $tenant->id, 'sip_number_id' => $number->id]);

                return ['number' => $number, 'credentials' => $credentials];
            });
        } catch (\Throwable $exception) {
            if ($newAudio !== null) {
                Storage::disk('ivr')->delete($newAudio);
            }
            throw $exception;
        }
        if ($setup->announcement_upload !== null) {
            Storage::disk('ivr')->delete($setup->announcement_upload);
            $setup->update(['announcement_upload' => null]);
        }

        return $result;
    }
}
