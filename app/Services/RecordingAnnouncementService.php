<?php

namespace App\Services;

use App\Models\SipNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class RecordingAnnouncementService
{
    public function validPath(SipNumber $number, ?string $path): bool
    {
        return is_string($path) && preg_match('#^recording-announcements/'.$number->tenant_id.'/'.$number->id.'/[0-9A-Z]+\.wav$#D', $path)
            && Storage::disk('ivr')->exists($path);
    }

    public function store(SipNumber $number, UploadedFile $upload): string
    {
        $disk = Storage::disk('ivr');
        $directory = 'recording-announcements/'.$number->tenant_id.'/'.$number->id;
        $id = (string) Str::ulid();
        $source = $upload->storeAs($directory, $id.'.upload', 'ivr');
        $output = $directory.'/'.$id.'.wav';
        $converted = false;
        try {
            $process = new Process(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $disk->path($source), '-vn', '-ac', '1', '-ar', '8000', '-c:a', 'pcm_s16le', '-t', '15', $disk->path($output)]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful() || ! $disk->exists($output) || $disk->size($output) <= 44) {
                throw new \RuntimeException('Invalid announcement audio.');
            }
            chmod($disk->path($output), 0644);
            $converted = true;

            return $output;
        } catch (Throwable) {
            throw ValidationException::withMessages(['announcement' => 'پیام صوتی قابل تبدیل نیست. یک فایل WAV، MP3 یا M4A معتبر بارگذاری کنید.']);
        } finally {
            $disk->delete($source);
            if (! $converted) {
                $disk->delete($output);
            }
        }
    }
}
