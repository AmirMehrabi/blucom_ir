<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Services\Commerce\LineEntitlementService;
use Illuminate\Http\UploadedFile;
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

        $menu->update([
            'name' => $data['name'],
            'draft_config' => [
                'greeting' => $greeting,
                'choices' => $choices,
                'fallback' => $fallback,
            ],
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

    }

    public function destination(IvrMenu $menu, string $choice): SipExtension|CallQueue|null
    {
        if (! preg_match('/^(extension|queue):([1-9][0-9]*)$/D', $choice, $matches)) {
            return null;
        }
        if ($matches[1] === 'extension') {
            return SipExtension::query()->where('tenant_id', $menu->tenant_id)
                ->where('enabled', true)->find((int) $matches[2]);
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
