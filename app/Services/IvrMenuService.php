<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Services\Commerce\LineEntitlementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class IvrMenuService
{
    /** @param array<string, mixed> $data */
    public function saveDraft(IvrMenu $menu, array $data, ?UploadedFile $upload): void
    {
        $current = $menu->draft_config ?? $menu->published_config ?? [];
        $greeting = $current['greeting'] ?? null;
        if ($upload !== null) {
            $greeting = $this->storeGreeting($menu, $upload);
        }

        $choices = [];
        foreach ($data['choices'] ?? [] as $digit => $choice) {
            if (! is_array($choice) || empty($choice['destination'])) {
                continue;
            }
            $this->assertDestination($menu, (string) $choice['destination']);
            $choices[(string) $digit] = [
                'label' => trim((string) ($choice['label'] ?? '')),
                'destination' => (string) $choice['destination'],
            ];
        }

        $fallback = (string) ($data['fallback'] ?? '');
        if ($fallback !== '') {
            $this->assertDestination($menu, $fallback);
        }

        $directDial = [
            'direct_dial_enabled' => (bool) ($data['direct_dial_enabled'] ?? false),
            'direct_dial_extension_ids' => array_values(array_unique(array_map('intval', $data['direct_dial_extension_ids'] ?? []))),
        ];
        $this->assertDirectDial($menu, $directDial);

        $menu->update([
            'name' => $data['name'],
            'draft_config' => [
                'greeting' => $greeting,
                'choices' => $choices,
                'fallback' => $fallback,
            ] + $directDial,
        ]);
    }

    public function publish(IvrMenu $menu): void
    {
        if (! $menu->enabled) {
            app(LineEntitlementService::class)->assertCapacity($menu->tenant, 'ivr_menus');
        }
        $config = $menu->draft_config;
        $this->assertPublishable($menu, $config);

        $menu->update([
            'previous_config' => $menu->published_config,
            'published_config' => $config,
            'version' => $menu->version + 1,
            'published_at' => now(),
            'enabled' => true,
        ]);
    }

    public function restore(IvrMenu $menu): void
    {
        if ($menu->previous_config === null) {
            abort(404);
        }
        $this->assertPublishable($menu, $menu->previous_config);
        $current = $menu->published_config;
        $menu->update([
            'published_config' => $menu->previous_config,
            'previous_config' => $current,
            'version' => $menu->version + 1,
            'published_at' => now(),
        ]);
    }

    private function assertPublishable(IvrMenu $menu, ?array $config): void
    {
        if (! is_array($config) || ! is_string($config['greeting'] ?? null)
            || ! Storage::disk('ivr')->exists($config['greeting'])) {
            throw ValidationException::withMessages(['greeting' => 'ابتدا فایل پیام خوش‌آمدگویی را بارگذاری کنید.']);
        }
        if (empty($config['choices']) || ! is_array($config['choices'])) {
            throw ValidationException::withMessages(['choices' => 'حداقل یک گزینه برای منو تعیین کنید.']);
        }
        foreach ($config['choices'] as $digit => $choice) {
            if (! preg_match('/^[0-9]$/D', (string) $digit) || ! is_array($choice)
                || empty($choice['label']) || mb_strlen($choice['label']) > 60) {
                throw ValidationException::withMessages(['choices' => 'برای هر کلید، عنوان و مقصد معتبر تعیین کنید.']);
            }
            $this->assertDestination($menu, (string) ($choice['destination'] ?? ''));
        }
        $fallback = (string) ($config['fallback'] ?? '');
        if ($fallback === '') {
            throw ValidationException::withMessages(['fallback' => 'برای تماس بدون انتخاب، یک پاسخ‌گو تعیین کنید.']);
        }
        $this->assertDestination($menu, $fallback);
        $this->assertDirectDial($menu, $config);
        $this->assertMenuTree($menu, $config);
    }

    /** @return Collection<int, SipExtension> */
    public function directDialExtensions(IvrMenu $menu, array $config): Collection
    {
        if (($config['direct_dial_enabled'] ?? false) !== true
            || ! is_array($config['direct_dial_extension_ids'] ?? null)) {
            return collect();
        }

        return SipExtension::query()->where('tenant_id', $menu->tenant_id)
            ->where('enabled', true)->whereIn('id', $config['direct_dial_extension_ids'])
            ->orderBy('extension')->get()
            ->filter(fn (SipExtension $extension) => preg_match('/^[1-9][0-9]{2,8}$/D', $extension->extension) === 1);
    }

    private function assertDirectDial(IvrMenu $menu, array $config): void
    {
        if (($config['direct_dial_enabled'] ?? false) !== true) {
            return;
        }
        $ids = $config['direct_dial_extension_ids'] ?? [];
        if (! is_array($ids) || $ids === [] || count($ids) > 500
            || $this->directDialExtensions($menu, $config)->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'direct_dial_extension_ids' => 'حداقل یک داخلی فعال از همین مجموعه برای شماره‌گیری مستقیم انتخاب کنید.',
            ]);
        }
    }

    public function destination(IvrMenu $menu, string $choice): SipExtension|CallQueue|IvrMenu|null
    {
        if (! preg_match('/^(extension|queue|ivr):([1-9][0-9]*)$/D', $choice, $matches)) {
            return null;
        }
        if ($matches[1] === 'extension') {
            return SipExtension::query()->where('tenant_id', $menu->tenant_id)
                ->where('enabled', true)->find((int) $matches[2]);
        }
        if ($matches[1] === 'ivr') {
            return IvrMenu::query()->where('tenant_id', $menu->tenant_id)->where('enabled', true)->whereNotNull('published_config')->whereKeyNot($menu->id)->find((int) $matches[2]);
        }
        if (! config('voip.queues_enabled')) {
            return null;
        }

        return CallQueue::query()->where('tenant_id', $menu->tenant_id)->where('enabled', true)
            ->whereHas('members', fn ($query) => $query->where('enabled', true)->where('sip_extensions.tenant_id', $menu->tenant_id))
            ->whereDoesntHave('members', fn ($query) => $query->where('sip_extensions.tenant_id', '!=', $menu->tenant_id))
            ->find((int) $matches[2]);
    }

    private function assertDestination(IvrMenu $menu, string $choice): void
    {
        if ($this->destination($menu, $choice) === null) {
            throw ValidationException::withMessages(['choices' => 'فقط تلفن‌ها و تیم‌های فعال همین مجموعه را انتخاب کنید.']);
        }
    }

    private function assertMenuTree(IvrMenu $root, array $config, array $path = []): void
    {
        if (in_array($root->id, $path, true) || count($path) >= 5) {
            throw ValidationException::withMessages(['choices' => 'منوها نباید به خودشان برگردند؛ حداکثر ۵ مرحله منو مجاز است.']);
        }
        $path[] = $root->id;
        foreach ([...array_column($config['choices'] ?? [], 'destination'), $config['fallback'] ?? ''] as $choice) {
            if (preg_match('/^ivr:([1-9][0-9]*)$/D', $choice, $match)) {
                $child = IvrMenu::query()->where('tenant_id', $root->tenant_id)->where('enabled', true)->whereNotNull('published_config')->find($match[1]);
                if (! $child) {
                    throw ValidationException::withMessages(['choices' => 'منوی مقصد باید منتشرشده و متعلق به سازمان شما باشد.']);
                }
                $this->assertMenuTree($child, $child->published_config, $path);
            }
        }
    }

    /** Verify that a nested menu is reachable from the line's authorized root. */
    public function reachable(IvrMenu $root, int $target, array $seen = []): bool
    {
        if (in_array($root->id, $seen, true) || count($seen) >= 5) {
            return false;
        }
        if ($root->id === $target) {
            return true;
        }
        $seen[] = $root->id;
        foreach (array_filter([$root->published_config, $root->previous_config]) as $config) {
            foreach ([...array_column($config['choices'] ?? [], 'destination'), $config['fallback'] ?? ''] as $choice) {
                if (preg_match('/^ivr:([1-9][0-9]*)$/D', $choice, $match)) {
                    $child = IvrMenu::query()->where('tenant_id', $root->tenant_id)->where('enabled', true)->whereNotNull('published_config')->find($match[1]);
                    if ($child && $this->reachable($child, $target, $seen)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function storeGreeting(IvrMenu $menu, UploadedFile $upload): string
    {
        $disk = Storage::disk('ivr');
        $directory = $menu->tenant_id.'/'.$menu->id;
        $id = (string) Str::ulid();
        $source = $upload->storeAs($directory, $id.'.upload', 'ivr');
        $output = $directory.'/'.$id.'.wav';
        $converted = false;
        try {
            $process = new Process([
                'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $disk->path($source), '-vn', '-ac', '1', '-ar', '16000',
                '-c:a', 'pcm_s16le', '-t', '60', $disk->path($output),
            ]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful() || ! $disk->exists($output) || $disk->size($output) < 44) {
                throw ValidationException::withMessages(['greeting' => 'فایل صوتی قابل خواندن نیست. یک فایل WAV، MP3 یا M4A بارگذاری کنید.']);
            }
            chmod($disk->path($output), 0644);
            $converted = true;

            return $output;
        } finally {
            $disk->delete($source);
            if (! $converted) {
                $disk->delete($output);
            }
        }
    }
}
