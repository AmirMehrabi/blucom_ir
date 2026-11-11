<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAudioCallKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^bac_[a-f0-9]{64}$/D', $token), 401, 'Invalid API key.', ['WWW-Authenticate' => 'Bearer']);
        $key = DB::table('audio_call_keys')->where('digest', hash('sha256', $token))->first();
        $tenant = $key ? Tenant::query()->find($key->tenant_id) : null;
        abort_unless($tenant?->isActive() && $tenant->system_key === null, 401, 'Invalid API key.', ['WWW-Authenticate' => 'Bearer']);
        $request->attributes->set('audio_call_key', $key);
        $request->attributes->set('audio_call_tenant', $tenant);

        return $next($request);
    }
}
