<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        abort_unless($request->user()?->user_type === UserType::from($type) && ! $request->user()->isDisabled(), 403);

        return $next($request);
    }
}
