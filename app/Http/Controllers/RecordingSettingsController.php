<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\NumberRecordingSetting;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\RecordingAnnouncementService;
use App\Services\RecordingStorageService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordingSettingsController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly RecordingStorageService $storage, private readonly RecordingAnnouncementService $announcements) {}

    public function index(Request $request)
    {
        $numbers = SipNumber::query()->whereNotNull('tenant_id')->with(['recordingSetting', 'tenant']);
        if (! $request->user()->isAdmin()) {
            $numbers->where('tenant_id', $this->tenants->forUser($request->user())->id);
        }

        return view('recordings.settings-index', ['numbers' => $numbers->orderBy('number')->paginate(30)]);
    }

    public function edit(Request $request, int $number)
    {
        $number = $this->number($request, $number);

        return view('recordings.settings', [
            'number' => $number, 'setting' => $number->recordingSetting,
            'storageSetting' => $this->storage->settings($number->tenant_id),
            'usage' => $this->storage->usage($number->tenant_id),
            'existingCount' => CallRecording::query()->where('tenant_id', $number->tenant_id)
                ->where('sip_number_id', $number->id)->whereIn('status', ['ready', 'failed'])->count(),
        ]);
    }

    public function update(Request $request, int $number)
    {
        $number = $this->number($request, $number);
        $data = $request->validate([
            'directions' => ['required', Rule::in(['off', 'inbound', 'outbound', 'both'])],
            'coverage' => ['required', Rule::in(['conversation', 'full'])],
            'retention_days' => ['required', 'integer', 'between:1,365'],
            'max_minutes' => ['required', 'integer', 'between:1,120'],
            'announcement_enabled' => ['sometimes', 'boolean'],
            'announcement' => ['nullable', 'file', 'mimes:wav,mp3,m4a,mpga', 'max:8192'],
        ]);
        unset($data['announcement']);
        $data['announcement_enabled'] = $request->boolean('announcement_enabled');
        $path = $request->hasFile('announcement') ? $this->announcements->store($number, $request->file('announcement')) : $number->recordingSetting?->announcement_path;
        if ($data['announcement_enabled'] && ! $this->announcements->validPath($number, $path)) {
            throw ValidationException::withMessages(['announcement' => 'برای فعال کردن پیام، فایل صوتی بارگذاری کنید.']);
        }
        $data['announcement_path'] = $path;
        DB::transaction(function () use ($number, $data) {
            Tenant::query()->whereKey($number->tenant_id)->lockForUpdate()->firstOrFail();
            $current = SipNumber::query()->whereKey($number->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->tenant_id === $number->tenant_id, 409);
            $setting = NumberRecordingSetting::query()->firstOrNew(['sip_number_id' => $number->id]);
            $setting->fill($data + ['tenant_id' => $number->tenant_id]);
            $setting->version = $setting->exists ? $setting->version + 1 : 1;
            $setting->save();
        });
        Log::info('Number recording policy changed', ['tenant_id' => $number->tenant_id, 'sip_number_id' => $number->id, 'user_id' => $request->user()->id]);

        return back()->with('status', 'تنظیمات ذخیره شد و برای تماس‌های جدید اعمال می‌شود.');
    }

    public function announcement(Request $request, int $number)
    {
        $number = $this->number($request, $number);
        $path = $number->recordingSetting?->announcement_path;
        abort_unless($this->announcements->validPath($number, $path), 404);

        return response()->file(Storage::disk('ivr')->path($path), ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }

    public function storage(Request $request, int $tenant)
    {
        if (! $request->user()->isAdmin()) {
            abort_unless($this->tenants->forUser($request->user())->id === $tenant, 404);
        }
        $data = $request->validate([
            'quota_mb' => ['required', 'integer', 'min:128', 'max:'.config('voip.recordings.max_quota_mb')],
            'overflow' => ['required', Rule::in(['stop', 'oldest'])],
        ]);
        DB::transaction(function () use ($tenant, $data) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $setting = $this->storage->settings($tenant);
            $setting->update($data);
        });
        Log::info('Recording storage policy changed', ['tenant_id' => $tenant, 'user_id' => $request->user()->id]);

        return back()->with('status', 'تنظیمات فضای ضبط ذخیره شد.');
    }

    public function retention(Request $request, int $number)
    {
        $number = $this->number($request, $number);
        $data = $request->validate(['retention_days' => ['required', 'integer', 'between:1,365'], 'confirm' => ['required', 'accepted']]);
        $count = DB::transaction(function () use ($number, $data) {
            Tenant::query()->whereKey($number->tenant_id)->lockForUpdate()->firstOrFail();
            $recordings = CallRecording::query()->where('tenant_id', $number->tenant_id)
                ->where('sip_number_id', $number->id)->whereIn('status', ['ready', 'failed'])->with('callRecord')->get();
            foreach ($recordings as $recording) {
                $recording->update(['expires_at' => ($recording->callRecord?->ended_at ?? $recording->created_at)->copy()->addDays($data['retention_days'])]);
            }

            return $recordings->count();
        });
        Log::info('Existing recording retention changed', ['tenant_id' => $number->tenant_id, 'sip_number_id' => $number->id, 'count' => $count, 'user_id' => $request->user()->id]);

        return back()->with('status', 'زمان نگهداری '.$count.' فایل به‌روزرسانی شد. فایل‌های منقضی در اجرای بعدی پاک‌سازی حذف می‌شوند.');
    }

    private function number(Request $request, int $id): SipNumber
    {
        $query = SipNumber::query()->whereNotNull('tenant_id');
        if (! $request->user()->isAdmin()) {
            $query->where('tenant_id', $this->tenants->forUser($request->user())->id);
        }

        return $query->findOrFail($id);
    }
}
