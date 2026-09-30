<?php

namespace App\Services\FreeSwitch;

use RuntimeException;

/** Minimal inbound ESL transport. Commands are issued only by the monitor. */
class EventSocket
{
    private $stream = null;

    public function connect(): void
    {
        $password = (string) config('voip.live.password');
        if ($password === '' || str_contains($password, "\n") || str_contains($password, "\r")) {
            throw new RuntimeException('Event Socket credentials are not configured.');
        }
        $this->stream = @stream_socket_client(
            'tcp://'.config('voip.live.host').':'.config('voip.live.port'),
            $error, $message, 5,
        );
        if (! is_resource($this->stream)) {
            throw new RuntimeException('Event Socket is unavailable.');
        }
        stream_set_timeout($this->stream, 5);
        if (($this->read(5)['headers']['Content-Type'] ?? '') !== 'auth/request') {
            throw new RuntimeException('Unexpected Event Socket handshake.');
        }
        $this->send('auth '.$password);
        if (($this->read(5)['headers']['Reply-Text'] ?? '') !== '+OK accepted') {
            throw new RuntimeException('Event Socket authentication failed.');
        }
    }

    public function subscribe(): void
    {
        $this->send('event json CHANNEL_CREATE CHANNEL_PROGRESS CHANNEL_PROGRESS_MEDIA CHANNEL_ANSWER CHANNEL_BRIDGE CHANNEL_UNBRIDGE CHANNEL_CALLSTATE CHANNEL_HOLD CHANNEL_UNHOLD CHANNEL_HANGUP_COMPLETE CHANNEL_DESTROY CUSTOM sofia::register sofia::unregister sofia::expire');
        if (! str_starts_with($this->read(5)['headers']['Reply-Text'] ?? '', '+OK')) {
            throw new RuntimeException('Event subscription failed.');
        }
    }

    public function api(string $command): string
    {
        $this->send('api '.$command);
        $frame = $this->read(5);
        if (($frame['headers']['Content-Type'] ?? '') !== 'api/response') {
            throw new RuntimeException('Unexpected Event Socket response.');
        }

        return $frame['body'];
    }

    public function read(int $wait = 1): ?array
    {
        $read = [$this->stream];
        $write = $except = [];
        if (stream_select($read, $write, $except, $wait) === 0) {
            return null;
        }
        $headers = [];
        $size = 0;
        while (($line = fgets($this->stream)) !== false) {
            $size += strlen($line);
            if ($size > 65536) {
                throw new RuntimeException('Oversized Event Socket header.');
            }
            if (trim($line) === '') {
                break;
            }
            [$name, $value] = array_pad(explode(':', trim($line), 2), 2, '');
            $headers[$name] = trim($value);
        }
        if ($line === false) {
            throw new RuntimeException('Event Socket disconnected.');
        }
        $length = (int) ($headers['Content-Length'] ?? 0);
        if ($length < 0 || $length > 8 * 1024 * 1024) {
            throw new RuntimeException('Invalid Event Socket frame size.');
        }
        $body = '';
        while (strlen($body) < $length) {
            $part = fread($this->stream, $length - strlen($body));
            if ($part === false || $part === '') {
                throw new RuntimeException('Incomplete Event Socket frame.');
            }
            $body .= $part;
        }

        return ['headers' => $headers, 'body' => $body];
    }

    private function send(string $command): void
    {
        if (str_contains($command, "\n") || str_contains($command, "\r")) {
            throw new RuntimeException('Invalid Event Socket command.');
        }
        $data = $command."\n\n";
        while ($data !== '') {
            $written = fwrite($this->stream, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write to Event Socket.');
            }
            $data = substr($data, $written);
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
