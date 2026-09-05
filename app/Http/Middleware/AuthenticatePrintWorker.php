<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('printing.token');

        if (! is_string($token) || trim($token) === ''
            || ! hash_equals($token, $request->bearerToken() ?? '')) {
            return response()->json(['message' => 'Unauthorized.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
