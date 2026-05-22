<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyEaToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.ea.token', env('EA_PUSH_TOKEN'));

        if ($expected === '') {
            abort(500, 'EA_PUSH_TOKEN not configured');
        }

        $bearer = $request->bearerToken();
        $custom = $request->header('X-EA-Token');
        $supplied = $bearer ?: $custom;

        if (! hash_equals($expected, (string) $supplied)) {
            abort(401, 'Invalid EA token');
        }

        return $next($request);
    }
}
