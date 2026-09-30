<?php

namespace App\Services;

use RuntimeException;

class RecordingWaveInspector
{
    /** Verify a finalized, bounded stereo PCM WAV without loading it in memory. */
    public function inspect(string $path, int $maxBytes): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (is_link($path) || $size === false || $size < 44 || $size > $maxBytes) {
            throw new RuntimeException('invalid_audio');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('unreadable_audio');
        }
        try {
            $header = fread($handle, 12);
            if (strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE'
                || unpack('V', substr($header, 4, 4))[1] + 8 !== $size) {
                throw new RuntimeException('unfinished_audio');
            }
            $rate = null;
            $dataBytes = null;
            for ($chunks = 0; $chunks < 64 && ftell($handle) + 8 <= $size; $chunks++) {
                $chunk = fread($handle, 8);
                $length = unpack('V', substr($chunk, 4, 4))[1];
                if (ftell($handle) + $length > $size) {
                    throw new RuntimeException('invalid_audio');
                }
                if (substr($chunk, 0, 4) === 'fmt ') {
                    if ($length < 16 || $length > 1024) {
                        throw new RuntimeException('invalid_audio');
                    }
                    $fmt = unpack('vformat/vchannels/Vsample_rate/Vbyte_rate/valign/vbits', fread($handle, 16));
                    if ($fmt['format'] !== 1 || $fmt['channels'] !== 2 || $fmt['sample_rate'] !== 8000
                        || $fmt['byte_rate'] !== 32000 || $fmt['align'] !== 4 || $fmt['bits'] !== 16) {
                        throw new RuntimeException('unsupported_audio');
                    }
                    $rate = $fmt['byte_rate'];
                    fseek($handle, $length - 16, SEEK_CUR);
                } else {
                    if (substr($chunk, 0, 4) === 'data') {
                        $dataBytes = $length;
                    }
                    fseek($handle, $length, SEEK_CUR);
                }
                if ($length % 2) {
                    fseek($handle, 1, SEEK_CUR);
                }
            }
            if ($rate === null || $dataBytes === null || $dataBytes % 4 !== 0) {
                throw new RuntimeException('invalid_audio');
            }

            return ['bytes' => $size, 'duration_seconds' => (int) ceil($dataBytes / $rate), 'empty' => $dataBytes === 0];
        } finally {
            fclose($handle);
        }
    }
}
