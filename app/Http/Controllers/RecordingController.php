<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\CallRecording;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\RecordingStorageService;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecordingController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly RecordingStorageService $storage) {}

    private function scope(Request $request, Builder $query): Builder
    {
        if (! $request->user()->isAdmin()) {
            $query->where('tenant_id', $this->tenants->forUser($request->user())->id);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'status' => ['nullable', Rule::in(['recording', 'processing', 'ready', 'failed', 'empty', 'skipped', 'expired', 'deleted'])],
            'number' => ['nullable', 'integer'], 'extension' => ['nullable', 'integer'], 'team' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'play' => ['nullable', 'uuid'],
        ]);
        $query = $this->scope($request, CallRecording::query())->with(['sipNumber', 'callRecord.sipExtension', 'callRecord.callQueue']);
        foreach (['status', 'direction'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['number'])) {
            $query->where('sip_number_id', $filters['number']);
        }
        foreach (['extension' => 'sip_extension_id', 'team' => 'call_queue_id'] as $field => $column) {
            if (! empty($filters[$field])) {
                $query->whereHas('callRecord', fn ($call) => $call->where($column, $filters[$field]));
            }
        }
        $timezone = config('voip.display_timezone', 'Asia/Tehran');
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (! empty($filters[$field])) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters[$field], $timezone);
                $query->where('created_at', $operator, ($field === 'from' ? $date->startOfDay() : $date->endOfDay())->utc());
            }
        }
        if (! empty($filters['search'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $query->where(fn ($query) => $query->whereHas('callRecord', fn ($call) => $call
                ->where(fn ($call) => $call->where('source_number', 'like', '%'.$search.'%')->orWhere('destination_number', 'like', '%'.$search.'%')))
                ->orWhereHas('sipNumber', fn ($number) => $number->where('number', 'like', '%'.$search.'%')));
        }
        $playing = ! empty($filters['play']) ? $this->scope($request, CallRecording::query())
            ->with(['callRecord.sipExtension', 'sipNumber'])->whereKey($filters['play'])->firstOrFail() : null;
        $usageQuery = $this->scope($request, CallRecording::query());

        return view('recordings.index', [
            'recordings' => $query->orderByDesc('created_at')->paginate(20)->withQueryString(),
            'numbers' => $this->scope($request, SipNumber::query())->orderBy('number')->get(),
            'extensions' => $this->scope($request, SipExtension::query())->orderBy('extension')->get(),
            'teams' => $this->scope($request, CallQueue::query())->orderBy('name')->get(),
            'filters' => $filters, 'timezone' => $timezone, 'playing' => $playing,
            'usedBytes' => (int) (clone $usageQuery)->sum('bytes'),
            'reservedBytes' => (int) (clone $usageQuery)->sum('reserved_bytes'),
            'expiringCount' => (clone $usageQuery)->where('status', 'ready')->where('expires_at', '<=', now()->addDays(7))->count(),
            'storageSetting' => $request->user()->isAdmin() ? null : $this->storage->settings($this->tenants->forUser($request->user())->id),
        ]);
    }

    public function audio(Request $request, string $recording): BinaryFileResponse
    {
        $record = $this->owned($request, $recording);
        $download = $request->routeIs('recordings.download');
        abort_unless($record->status === 'ready' && $record->expires_at?->isFuture()
            && $record->storage_key === $record->tenant_id.'/'.$record->id.'.wav', 404);
        $path = Storage::disk('recordings')->path($record->storage_key);
        abort_unless(is_file($path) && ! is_link($path), 404);
        Log::info($download ? 'Recording downloaded' : 'Recording played', [
            'recording_id' => $record->id, 'tenant_id' => $record->tenant_id, 'user_id' => $request->user()->id,
        ]);
        $response = response()->file($path, ['Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition($download ? 'attachment' : 'inline', 'call-'.$record->created_at->format('Ymd-His').'-'.$record->id.'.wav');

        return $response;
    }

    public function destroy(Request $request, string $recording)
    {
        $record = $this->owned($request, $recording);
        abort_if(in_array($record->status, ['recording', 'processing'], true), 409);
        $request->validate(['confirm' => ['required', 'accepted']]);
        try {
            $this->storage->delete($record);
        } catch (\RuntimeException) {
            return back()->withErrors(['recording' => 'حذف کامل فایل هنوز انجام نشده است. فایل از دسترس خارج شده و پاک‌سازی دوباره تلاش می‌کند.']);
        }

        return redirect()->route('recordings.index')->with('status', 'صدای تماس حذف شد. تاریخچه تماس باقی می‌ماند.');
    }

    private function owned(Request $request, string $id): CallRecording
    {
        return $this->scope($request, CallRecording::query())->findOrFail($id);
    }
}
