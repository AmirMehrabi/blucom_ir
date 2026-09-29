<?php

namespace App\Services;

use App\Models\SipNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

class InboundAnnouncementService
{
    public function store(SipNumber $number, UploadedFile $upload): string
    {
        $disk = Storage::disk('ivr');
        $directory = 'announcements/'.$number->tenant_id.'/'.$number->id;
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
            try {
                $process->run();
            } catch (ProcessStartFailedException) {
                throw ValidationException::withMessages(['announcement' => 'پردازش فایل صوتی روی سرور آماده نیست. لطفاً با پشتیبانی تماس بگیرید.']);
            }
            if (! $process->isSuccessful() || ! $disk->exists($output) || $disk->size($output) < 44) {
                throw ValidationException::withMessages(['announcement' => 'فایل صوتی خوانده نشد. فایل WAV، MP3 یا M4A بارگذاری کنید.']);
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
