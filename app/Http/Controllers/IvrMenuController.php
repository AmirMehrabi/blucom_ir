<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Services\IvrMenuService;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IvrMenuController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly IvrMenuService $menus,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenants->forUser($request->user());

        return view('ivr-menus.index', [
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->withCount('inboundRoutes')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('ivr_menus')->where('tenant_id', $tenant->id)],
        ]);
        $menu = IvrMenu::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'draft_config' => ['greeting' => null, 'choices' => [], 'fallback' => ''],
        ]);
        Log::info('Call menu created', ['tenant_id' => $tenant->id, 'ivr_menu_id' => $menu->id]);

        return redirect()->route('ivr-menus.edit', ['menu' => $menu->id] + ($request->boolean('wizard') ? ['wizard' => 1] : []));
    }

    public function edit(Request $request, int $menu): View
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);

        return view('ivr-menus.edit', [
            'menu' => $record,
            'extensions' => $tenant->sipExtensions()->where('enabled', true)->orderBy('extension')->get(),
            'queues' => config('voip.queues_enabled')
                ? CallQueue::query()->whereBelongsTo($tenant)->where('enabled', true)
                    ->whereHas('members', fn ($query) => $query->where('enabled', true))->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function update(Request $request, int $menu): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('ivr_menus')->where('tenant_id', $tenant->id)->ignore($record->id)],
            'greeting' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm,video/webm,application/octet-stream'],
            'choices' => ['required', 'array'],
            'choices.*.label' => ['nullable', 'string', 'max:60'],
            'choices.*.destination' => ['nullable', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
            'fallback' => ['nullable', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
        ]);
        if (array_diff(array_keys($data['choices']), range(0, 9))) {
            throw ValidationException::withMessages(['choices' => 'کلیدهای منو باید بین ۰ تا ۹ باشند.']);
        }
        $this->menus->saveDraft($record, $data, $request->file('greeting'));
        Log::info('Call menu draft saved', ['tenant_id' => $tenant->id, 'ivr_menu_id' => $record->id]);

        return back()->with('status', 'پیش‌نویس منو ذخیره شد. برای فعال شدن تغییرات، انتشار را بزنید.');
    }

    public function publish(Request $request, int $menu): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);
        $this->menus->publish($record);
        Log::info('Call menu published', ['tenant_id' => $tenant->id, 'ivr_menu_id' => $record->id, 'version' => $record->version]);

        return back()->with('status', 'منوی تماس منتشر شد. اکنون می‌توانید آن را برای شماره انتخاب کنید.');
    }

    public function restore(Request $request, int $menu): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);
        $this->menus->restore($record);
        Log::info('Call menu version restored', ['tenant_id' => $tenant->id, 'ivr_menu_id' => $record->id, 'version' => $record->version]);

        return back()->with('status', 'نسخه قبلی منو فعال شد.');
    }

    public function destroy(Request $request, int $menu): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);
        if (InboundRoute::query()->where(fn ($query) => $query
            ->where('destination_type', InboundRoute::DESTINATION_IVR)->where('destination_id', $record->id))
            ->orWhere(fn ($query) => $query->where('closed_destination_type', InboundRoute::DESTINATION_IVR)
                ->where('closed_destination_id', $record->id))->exists()) {
            throw ValidationException::withMessages(['menu' => 'ابتدا شماره‌های متصل به این منو را به پاسخ‌گوی دیگری وصل کنید.']);
        }
        $record->delete();
        Storage::disk('ivr')->deleteDirectory($tenant->id.'/'.$record->id);
        Log::info('Call menu deleted', ['tenant_id' => $tenant->id, 'ivr_menu_id' => $record->id]);

        return redirect()->route('ivr-menus.index')->with('status', 'منو حذف شد.');
    }

    public function audio(Request $request, int $menu, string $version): BinaryFileResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $record = IvrMenu::query()->whereBelongsTo($tenant)->findOrFail($menu);
        $config = match ($version) {
            'draft' => $record->draft_config,
            'published' => $record->published_config,
            'previous' => $record->previous_config,
            default => null,
        };
        $path = $config['greeting'] ?? null;
        abort_unless(is_string($path) && preg_match('#^'.$tenant->id.'/'.$record->id.'/[0-9A-Z]+\.wav$#D', $path)
            && Storage::disk('ivr')->exists($path), 404);

        return response()->file(Storage::disk('ivr')->path($path), [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
