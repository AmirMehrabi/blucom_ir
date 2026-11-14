<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DestinationRecordingController extends Controller
{
    public function update(Request $request, string $destination, int $id, TenantService $tenants): RedirectResponse
    {
        $tenant = $tenants->forUser($request->user());
        $model = match ($destination) {
            'queue' => CallQueue::class,
            'ivr' => IvrMenu::class,
            'extension' => SipExtension::class,
        };
        $record = $model::query()->whereBelongsTo($tenant)->findOrFail($id);
        $section = 'recording-'.$destination.'-'.$record->id;
        $response = back()->withFragment($section);
        $validation = Validator::make($request->only('recording_mode'), [
            'recording_mode' => 'required|in:inherit,always,never',
        ], [
            'recording_mode.required' => 'روش ضبط را انتخاب کنید.',
            'recording_mode.in' => 'یکی از روش‌های ضبط موجود را انتخاب کنید.',
        ]);
        if ($validation->fails()) {
            return $response->withErrors($validation, $section);
        }

        $record->recording_mode = $validation->validated()['recording_mode'];
        if ($record->isDirty('recording_mode')) {
            $record->save();
            Log::info('Destination recording setting updated', [
                'tenant_id' => $tenant->id, 'destination' => $destination, 'destination_id' => $id,
                'recording_mode' => $record->recording_mode,
            ]);
        }

        return $response->with('recording_saved', $section);
    }
}
