<?php

namespace App\Services\OutboundCalling;

use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class AudioAssetService
{
    public function path(Tenant $tenant, ?string $id): ?string
    {
        if (! $id) {
            return null;
        }
        $asset = TenantAudioAsset::query()->where('tenant_id', $tenant->id)->find($id);
        if (! $asset || ! preg_match('#^two-party/'.$tenant->id.'/[a-f0-9-]{36}\.wav$#D', $asset->storage_key)) {
            throw ValidationException::withMessages(['audio' => 'پیام صوتی معتبر نیست.']);
        }
        $path = Storage::disk('ivr')->path($asset->storage_key);
        if (! is_file($path) || is_link($path) || ! preg_match('#^/[a-zA-Z0-9_./-]+$#D', $path)) {
            throw ValidationException::withMessages(['audio' => 'فایل پیام صوتی در دسترس نیست.']);
        }

        return $path;
    }

    public function convert(string $source, string $target, bool $strict = true): int
    {
        $input = [];
        if ($strict) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
            $format = match ($mime) {
                'audio/wav', 'audio/x-wav', 'audio/vnd.wave' => 'wav',
                'audio/mpeg' => 'mp3',
                default => throw new \RuntimeException('Unsupported audio format.'),
            };
            $input = ['-f', $format, '-protocol_whitelist', 'file'];
        }
        $process = new Process(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            ...$input, '-i', $source, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            '-t', $strict ? '61' : '60', '-fflags', '+bitexact', '-f', 'wav', $target]);
        $process->setTimeout($strict ? 8 : 30);
        $process->mustRun();
        $bytes = filesize($target);
        if ($bytes <= 44 || $bytes > ($strict ? 1920044 : 2000000)) {
            throw new \RuntimeException('Invalid audio length.');
        }
        chmod($target, 0644);

        return (int) ceil(($bytes - 44) / 32000);
    }

    public function store(Tenant $tenant, string $name, UploadedFile $upload, bool $strict = false): TenantAudioAsset
    {
        $id = (string) Str::uuid();
        $key = 'two-party/'.$tenant->id.'/'.$id.'.wav';
        $disk = Storage::disk('ivr');
        $source = $upload->storeAs('two-party/'.$tenant->id, $id.'.upload', 'ivr');
        try {
            $duration = $this->convert($disk->path($source), $disk->path($key), $strict);

            return TenantAudioAsset::query()->create(['id' => $id, 'tenant_id' => $tenant->id, 'name' => $name,
                'storage_key' => $key, 'duration_seconds' => $duration]);
        } catch (\Throwable) {
            $disk->delete($key);
            throw ValidationException::withMessages(['audio' => 'پردازش پیام صوتی ناموفق بود. فایل معتبر تا ۶۰ ثانیه انتخاب کنید.']);
        } finally {
            $disk->delete($source);
        }
    }
}
