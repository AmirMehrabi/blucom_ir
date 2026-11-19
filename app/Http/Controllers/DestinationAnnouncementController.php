<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Services\InboundAudioService;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DestinationAnnouncementController extends Controller
{
    public function update(Request $request, string $destination, int $id, TenantService $tenants, InboundAudioService $audio): RedirectResponse
    {
        $tenant = $tenants->forUser($request->user());
        $isQueue = $destination === 'queue';
        $model = $isQueue ? CallQueue::class : SipExtension::class;
        $section = 'announcement-'.$destination.'-'.$id;
        $response = back()->withFragment($section);
        $input = $request->only('announcement_form', 'mode', 'asset', 'digit', 'mailbox');

        try {
            DB::transaction(function () use ($tenant, $model, $id, $input, $isQueue, $audio): void {
                Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                $record = $model::query()->whereBelongsTo($tenant)->lockForUpdate()->findOrFail($id);
                $data = Validator::make($input, [
                    'mode' => ['required', 'in:normal,playback,message'],
                    'asset' => ['exclude_if:mode,normal', 'required', 'uuid'],
                    'digit' => ['exclude_unless:mode,message', 'required', 'regex:/^[0-9]$/D'],
                    'mailbox' => $isQueue ? ['exclude_unless:mode,message', 'required', 'integer', 'min:1'] : ['exclude'],
                ], [], [
                    'mode' => 'رفتار تماس ورودی',
                    'asset' => 'فایل اعلان',
                    'digit' => 'کلید ضبط پیام',
                    'mailbox' => 'صندوق صوتی',
                ])->validate();

                $settings = null;
                if ($data['mode'] !== 'normal') {
                    $asset = strtolower($data['asset']);
                    $audio->configure($tenant->id, 'announcement:'.$asset, 'asset', $asset === ($record->inbound_announcement['asset'] ?? null));
                    $settings = ['mode' => $data['mode'], 'asset' => $asset];
                    if ($data['mode'] === 'message') {
                        $mailbox = $isQueue ? (int) $data['mailbox'] : $record->id;
                        $audio->configure($tenant->id, 'voicemail:'.$mailbox, $isQueue ? 'mailbox' : 'mode');
                        $settings += ['digit' => (string) $data['digit'], 'mailbox' => $mailbox];
                    }
                }
                $record->inbound_announcement = $settings;
                $record->save();
            }, 3);
        } catch (ValidationException $exception) {
            return $response->withInput($input)->withErrors($exception->errors(), $section);
        }

        Log::info('Destination inbound announcement updated', [
            'tenant_id' => $tenant->id, 'destination' => $destination, 'destination_id' => $id,
        ]);

        return $response->with('announcement_saved', $section);
    }
}
