<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKitchenAvailabilityAccess
{
    public const SESSION_KEY = 'kitchen_availability_authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows()) {
            return redirect()->guest(route('kitchen.availability.login'));
        }

        return $next($request);
    }

    public static function allows(): bool
    {
        $pinHash = config('kitchen.availability_pin_hash');
        $sessionFingerprint = session(self::SESSION_KEY);

        return is_string($pinHash)
            && $pinHash !== ''
            && is_string($sessionFingerprint)
            && hash_equals(self::fingerprint($pinHash), $sessionFingerprint);
    }

    public static function fingerprint(string $pinHash): string
    {
        return hash('sha256', $pinHash);
    }
}
