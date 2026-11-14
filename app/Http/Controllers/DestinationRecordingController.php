<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $record->recording_mode = $request->validate(['recording_mode' => 'required|in:inherit,always,never'])['recording_mode'];
        if ($record->isDirty('recording_mode')) {
            $record->save();
            Log::info('Destination recording setting updated', [
                'tenant_id' => $tenant->id, 'destination' => $destination, 'destination_id' => $id,
                'recording_mode' => $record->recording_mode,
            ]);
        }

        return back()->with('status', 'تنظیم ضبط تماس ذخیره شد.');
    }
}
