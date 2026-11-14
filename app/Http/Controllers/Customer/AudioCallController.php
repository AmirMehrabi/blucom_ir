<?php

namespace App\Http\Controllers\Customer;

use App\Models\AudioCall;
use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use App\Services\AudioCalls\AudioCallPolicy;
use App\Services\AudioCalls\FetchAudio;
use App\Services\AudioCalls\RequestAudioCall;
use App\Services\OutboundCalling\AudioAssetService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AudioCallController
{
    public function __construct(private AudioCallPolicy $policy, private AudioAssetService $assets, private TenantService $tenants) {}

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->view('audio-calls.index', [
            'lines' => $this->policy->lines($tenant),
            'assets' => TenantAudioAsset::query()->where('tenant_id', $tenant->id)->where('storage_key', 'not like', 'inbound/%')->whereNull('archived_at')->orderBy('name')->get(),
            'calls' => $this->calls($request)->latest()->paginate(20),
            'canManage' => $request->user()->hasPermission('outbound.manage'),
            'requestKey' => (string) Str::uuid(),
            'prefill' => $request->only(['to', 'caller_id_id', 'audio_id']),
            'keys' => ! $request->session()->has('impersonator_id') && $tenant->owner_customer_id === $request->user()->id
                ? DB::table('audio_call_keys')->where('tenant_id', $tenant->id)->get(['id', 'name', 'created_at']) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RequestAudioCall $action)
    {
        $api = $request->attributes->has('audio_call_key');
        if (! $api && $request->boolean('test_call')) {
            abort_unless($request->user()->mobile_verified_at, 422, 'Verify your mobile number first.');
            $request->merge(['to' => $request->user()->mobile]);
        }
        $data = $request->validate([
            'to' => ['required', 'string', 'max:40'],
            'caller_id_id' => ['nullable', 'integer', 'min:1'],
            'audio_id' => ['nullable', 'required_without:audio_url', 'prohibits:audio_url', 'uuid'],
            'audio_url' => ['nullable', 'required_without:audio_id', 'prohibits:audio_id', 'url:https', 'max:2048'],
            'tenant_id' => ['prohibited'], 'gateway' => ['prohibited'],
        ]);
        $key = Validator::make(['idempotency_key' => $api ? $request->header('Idempotency-Key') : $request->input('idempotency_key')], [
            'idempotency_key' => ['required', 'string', 'regex:/^[a-zA-Z0-9._:-]{1,100}$/D'],
        ])->validate()['idempotency_key'];
        $call = $action->handle($this->tenant($request), $data, $key, $api ? null : $request->user()->id,
            $request->attributes->get('audio_call_key')?->id);

        if (! $api && $request->session()->has('impersonator_id') && $call->wasRecentlyCreated) {
            Log::info('Audio call requested during customer impersonation', [
                'admin_id' => $request->session()->get('impersonator_id'),
                'customer_id' => $request->user()->id,
                'tenant_id' => $call->tenant_id,
                'audio_call_id' => $call->id,
            ]);
        }

        return $api ? response()->json($call->resource(), 202)
            ->header('Location', route('api.audio-calls.show', $call->id))->header('Cache-Control', 'private, no-store')
            : redirect()->route('customer.audio-calls.show', $call->id);
    }

    public function show(Request $request, string $call)
    {
        $record = $this->calls($request)->findOrFail($call);
        if ($request->attributes->has('audio_call_key') || $request->expectsJson()) {
            return response()->json($record->resource())->header('Cache-Control', 'private, no-store');
        }

        return response()->view('audio-calls.show', ['call' => $record])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, FetchAudio $fetch)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'audio' => ['nullable', 'required_without:audio_url', 'prohibits:audio_url', 'file', 'max:2048'],
            'audio_url' => ['nullable', 'required_without:audio', 'prohibits:audio', 'url:https', 'max:2048'],
        ]);
        $tenant = $this->tenant($request);
        abort_unless(config('audio_calls.enabled') && $this->policy->lines($tenant)->isNotEmpty(), 403);
        $temp = null;
        try {
            $upload = $data['audio'] ?? null;
            if (! $upload) {
                $temp = tempnam(sys_get_temp_dir(), 'blucom-audio-');
                try {
                    $upload = $fetch->download($data['audio_url'], $temp);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['audio_url' => 'The audio file could not be downloaded.']);
                }
            }
            $asset = $this->assets->store($tenant, $data['name'], $upload, strict: true);
        } finally {
            if ($temp && is_file($temp)) {
                unlink($temp);
            }
        }

        return $request->attributes->has('audio_call_key')
            ? response()->json($asset->only(['id', 'name', 'duration_seconds']), 201)
            : back()->with('status', 'فایل صوتی ذخیره شد.');
    }

    public function preview(Request $request, string $asset)
    {
        $tenant = $this->tenant($request);
        $this->asset($tenant, $asset);
        $path = $this->assets->path($tenant, $asset);

        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function updateAsset(Request $request, string $asset)
    {
        $data = $request->validate(['name' => ['required_unless:archive,1', 'nullable', 'string', 'max:80'], 'archive' => ['nullable', 'boolean']]);
        $record = $this->asset($this->tenant($request), $asset);
        $record->update($request->boolean('archive') ? ['archived_at' => now()] : ['name' => $data['name']]);

        return back();
    }

    public function createKey(Request $request)
    {
        $tenant = $this->owner($request);
        abort_unless($this->policy->lines($tenant)->isNotEmpty(), 403);
        $name = $request->validate(['name' => ['required', 'string', 'max:80']])['name'];
        $token = 'bac_'.bin2hex(random_bytes(32));
        DB::table('audio_call_keys')->insert([
            'tenant_id' => $tenant->id, 'name' => $name, 'digest' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->view('audio-calls.key', ['token' => $token])->header('Cache-Control', 'private, no-store');
    }

    public function revokeKey(Request $request, int $key)
    {
        DB::table('audio_call_keys')->where('tenant_id', $this->owner($request)->id)->where('id', $key)->delete();

        return back();
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('audio_call_tenant') ?? $this->tenants->forUser($request->user());
    }

    private function owner(Request $request): Tenant
    {
        $tenant = $this->tenant($request);
        abort_if($request->session()->has('impersonator_id') || $tenant->owner_customer_id !== $request->user()->id, 403);

        return $tenant;
    }

    private function calls(Request $request)
    {
        $query = AudioCall::query()->where('tenant_id', $this->tenant($request)->id);
        if (! $request->attributes->has('audio_call_key') && ! $request->user()->hasPermission('calls.view')) {
            $query->where('customer_id', $request->user()->id);
        }

        return $query;
    }

    private function asset(Tenant $tenant, string $id): TenantAudioAsset
    {
        return TenantAudioAsset::query()->where('tenant_id', $tenant->id)->where('storage_key', 'not like', 'inbound/%')->whereNull('archived_at')->findOrFail($id);
    }
}
