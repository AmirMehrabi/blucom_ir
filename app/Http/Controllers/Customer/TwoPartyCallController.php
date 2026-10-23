<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\OutboundCallSession;
use App\Models\OutboundRoute;
use App\Models\TenantAudioAsset;
use App\Services\OutboundCalling\AudioAssetService;
use App\Services\OutboundCalling\CallPolicyService;
use App\Services\OutboundCalling\InitiateTwoPartyCall;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TwoPartyCallController extends Controller
{
    public function __construct(private TenantService $tenants, private CallPolicyService $policy) {}

    public function index(Request $request)
    {
        $tenant = $this->tenants->forUser($request->user());
        $routes = OutboundRoute::query()->where('tenant_id', $tenant->id)->where('enabled', true)
            ->with(['sipExtension', 'sipNumber'])->get()->filter(function ($route) use ($tenant) {
                try {
                    $this->policy->route($tenant, $route->sip_extension_id, $route->sip_number_id);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            });

        return response()->view('two-party-calls.index', [
            'routes' => $routes, 'defaults' => $this->policy->settings($tenant), 'enabled' => $this->policy->enabled($tenant),
            'assets' => TenantAudioAsset::query()->where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'sessions' => OutboundCallSession::query()->where('tenant_id', $tenant->id)->latest()->paginate(20),
            'active' => OutboundCallSession::query()->where('tenant_id', $tenant->id)->whereNotIn('status', OutboundCallSession::TERMINAL)->when(! $request->user()->hasPermission(Permissions::CALLS_VIEW), fn ($q) => $q->where('initiated_by_customer_id', $request->user()->id))->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, InitiateTwoPartyCall $initiate)
    {
        $data = $request->validate($this->rules() + [
            'idempotency_key' => ['required', 'uuid'], 'agent_id' => ['required', 'integer'],
            'caller_id' => ['required', 'integer'], 'customer_number' => ['required', 'string', 'max:40'],
            'tenant_id' => ['prohibited'], 'gateway' => ['prohibited'],
        ]);
        $call = $initiate->handle($request->user(), $data);

        return response()->json($this->resource($call), 202)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $session)
    {
        abort_unless($request->user()->hasPermission(Permissions::CALLS_VIEW) || $request->user()->hasPermission(Permissions::OUTBOUND_INITIATE), 403);
        $call = $this->owned($request, $session);
        abort_unless($request->user()->hasPermission(Permissions::CALLS_VIEW) || $call->initiated_by_customer_id === $request->user()->id, 403);
        if ($request->expectsJson()) {
            return response()->json($this->resource($call))->header('Cache-Control', 'private, no-store');
        }

        return response()->view('two-party-calls.show', ['call' => $call, 'resource' => $this->resource($call)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, string $session)
    {
        $call = $this->owned($request, $session);
        DB::transaction(function () use ($call) {
            $call = OutboundCallSession::query()->lockForUpdate()->findOrFail($call->id);
            if (! $call->terminal() && ! $call->cancel_requested_at) {
                $call->update(['cancel_requested_at' => now()]);
                Log::info('Two-party cancellation requested', ['session_id' => $call->id, 'tenant_id' => $call->tenant_id, 'customer_id' => auth()->id()]);
            }
        });

        return response()->json($this->resource($call->fresh()))->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request, AudioAssetService $audio)
    {
        $tenant = $this->tenants->forUser($request->user());
        $data = $request->validate($this->rules(true));
        foreach (['agent_audio_id', 'customer_audio_id'] as $field) {
            $audio->path($tenant, $data[$field] ?? null);
        }
        DB::table('tenant_calling_settings')->updateOrInsert(['tenant_id' => $tenant->id], [
            'settings' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::info('Two-party defaults changed', ['tenant_id' => $tenant->id, 'customer_id' => $request->user()->id]);

        return back()->with('status', 'پیش‌فرض‌های تماس ذخیره شد.');
    }

    public function upload(Request $request, AudioAssetService $audio)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'audio' => ['required', 'file', 'max:10240', 'mimes:wav,mp3,m4a,mp4']]);
        $audio->store($this->tenants->forUser($request->user()), $data['name'], $request->file('audio'));

        return back()->with('status', 'پیام صوتی آماده شد.');
    }

    private function owned(Request $request, string $id): OutboundCallSession
    {
        return OutboundCallSession::query()->where('tenant_id', $this->tenants->forUser($request->user())->id)->findOrFail($id);
    }

    private function resource(OutboundCallSession $call): array
    {
        $recording = $call->recording;
        $actor = auth()->user();

        return [
            'id' => $call->id, 'status' => $call->status, 'status_label' => OutboundCallSession::LABELS[$call->status],
            'terminal' => $call->terminal(), 'cancel_requested' => (bool) $call->cancel_requested_at,
            'failure_reason' => $call->failure_reason ? (OutboundCallSession::FAILURES[$call->failure_reason] ?? 'تماس ناموفق') : null,
            'agent' => $call->agent_label, 'customer_number' => $call->customer_number, 'caller_id' => $call->caller_id,
            'agent_answered' => (bool) $call->agent_answered_at, 'customer_answered' => (bool) $call->customer_answered_at,
            'conversation_seconds' => $call->conversation_seconds, 'created_at' => $call->created_at->toIso8601String(),
            'events' => $call->events->map(fn ($e) => ['status' => OutboundCallSession::LABELS[$e->status] ?? $e->status,
                'at' => $e->occurred_at->toIso8601String()])->all(),
            'recording_status' => $recording?->statusLabel(),
            'recording_audio' => $recording?->status === 'ready' && $actor->hasPermission(Permissions::RECORDINGS_VIEW) ? route('recordings.audio', $recording->id) : null,
            'recording_download' => $recording?->status === 'ready' && $actor->hasPermission(Permissions::RECORDINGS_DOWNLOAD) ? route('recordings.download', $recording->id) : null,
            'url' => route('customer.two-party.show', $call->id), 'cancel_url' => route('customer.two-party.cancel', $call->id),
        ];
    }

    private function rules(bool $required = false): array
    {
        return [
            'agent_timeout' => [$required ? 'required' : 'sometimes', 'integer', 'between:5,90'],
            'customer_timeout' => [$required ? 'required' : 'sometimes', 'integer', 'between:5,90'],
            'recording' => ['sometimes', 'boolean'], 'require_confirmation' => ['sometimes', 'boolean'],
            'agent_audio_id' => ['nullable', 'uuid'], 'customer_audio_id' => ['nullable', 'uuid'],
        ];
    }
}
