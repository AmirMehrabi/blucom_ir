<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use App\Services\InboundAudioService;
use App\Services\OutboundCalling\AudioAssetService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaLibraryController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly AudioAssetService $audio) {}

    private function tenant(Request $request, bool $manage = false): Tenant
    {
        $user = $request->user('customer');
        abort_unless($user->hasPermission(Permissions::PHONES_MANAGE)
            || $user->hasPermission(Permissions::OUTBOUND_MANAGE)
            || (! $manage && $user->hasPermission(Permissions::OUTBOUND_INITIATE)), 403);

        return $this->tenants->forUser($user);
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $canEditPhones = $request->user('customer')->hasPermission(Permissions::PHONES_MANAGE);
        $assets = TenantAudioAsset::where('tenant_id', $tenant->id)->latest()->get();
        if ($request->expectsJson()) {
            return response()->json($assets->whereNull('archived_at')->map($this->item(...))->values())->header('Cache-Control', 'private, no-store');
        }
        $usage = [];
        if ($canEditPhones) {
            foreach (IvrMenu::where('tenant_id', $tenant->id)->get() as $menu) {
                foreach (['draft_config' => 'پیش‌نویس', 'published_config' => 'منتشرشده', 'previous_config' => 'نسخه قبلی'] as $field => $label) {
                    $config = $menu->$field ?? [];
                    foreach ([$config['greeting'] ?? '', $config['fallback'] ?? '', ...array_column($config['choices'] ?? [], 'destination')] as $reference) {
                        $reference = (string) $reference;
                        $key = str_starts_with($reference, 'announcement:') ? substr($reference, 13) : pathinfo($reference, PATHINFO_FILENAME);
                        $usage[$key][$menu->name.' · '.$label] = route('ivr-menus.edit', $menu);
                    }
                }
            }
            foreach (InboundRoute::where('tenant_id', $tenant->id)->with('sipNumber')->get() as $route) {
                foreach ([$route->audio_destination, $route->unanswered_audio_destination] as $reference) {
                    if (str_starts_with($reference ?? '', 'announcement:') && $route->sipNumber) {
                        $usage[substr($reference, 13)]['خط '.$route->sipNumber->normalized_number] = route('customer.lines.show', $route->sipNumber);
                    }
                }
            }
            foreach (CallQueue::where('tenant_id', $tenant->id)->get() as $queue) {
                if (str_starts_with($queue->fallback_audio_destination ?? '', 'announcement:')) {
                    $usage[substr($queue->fallback_audio_destination, 13)]['صف '.$queue->name] = route('teams.index');
                }
            }
        }

        return view('media-library.index', [
            'assets' => $assets, 'usage' => $usage,
            'canManage' => $canEditPhones || $request->user('customer')->hasPermission(Permissions::OUTBOUND_MANAGE),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'audio' => ['required', 'file', 'max:10240', 'mimes:wav,mp3,mpga'],
        ]);
        $asset = $this->audio->store($tenant, $data['name'], $data['audio'], strict: true, inbound: true);

        return $request->expectsJson() ? response()->json($this->item($asset), 201) : back()->with('status', 'فایل صوتی ذخیره شد.');
    }

    public function importGreeting(Request $request, int $menu)
    {
        $tenant = $this->tenant($request, true);
        abort_unless($request->user('customer')->hasPermission(Permissions::PHONES_MANAGE), 403);
        $record = IvrMenu::where('tenant_id', $tenant->id)->findOrFail($menu);
        $key = $record->draft_config['greeting'] ?? '';
        abort_unless(preg_match('#^'.$tenant->id.'/'.$record->id.'/[0-9A-Z]+\.wav$#D', $key), 404);
        $path = Storage::disk('ivr')->path($key);
        abort_unless(is_file($path) && ! is_link($path), 404);
        $asset = $this->audio->store($tenant, $record->name, new UploadedFile($path, 'greeting.wav', 'audio/wav', UPLOAD_ERR_OK, true), strict: true, inbound: true);

        return response()->json($this->item($asset), 201);
    }

    public function preview(Request $request, string $asset)
    {
        $tenant = $this->tenant($request);
        $path = app(InboundAudioService::class)->announcement($tenant->id, 'announcement:'.$asset);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, string $asset)
    {
        $tenant = $this->tenant($request, true);
        $data = $request->validate(['action' => ['required', 'in:rename,archive,restore'], 'name' => ['required_if:action,rename', 'nullable', 'string', 'max:100']]);
        $record = TenantAudioAsset::where('tenant_id', $tenant->id)->findOrFail($asset);
        $record->update(match ($data['action']) {
            'rename' => ['name' => $data['name']],
            'archive' => ['archived_at' => now()],
            'restore' => ['archived_at' => null],
        });

        return back()->with('status', 'تغییرات ذخیره شد.');
    }

    private function item(TenantAudioAsset $asset): array
    {
        return ['id' => $asset->id, 'name' => $asset->name, 'duration' => $asset->duration_seconds,
            'preview' => route('customer.media.preview', $asset)];
    }
}
