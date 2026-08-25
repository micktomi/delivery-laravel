<?php

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'kitchen.availability' => EnsureKitchenAvailabilityAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'payments/viva/webhook',
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('driver', 'driver/*')
            ? route('driver.login')
            : route('filament.admin.auth.login'));

        // Behind nginx / Cloudflare the app must see the real client IP and
        // scheme, otherwise rate limiting and generated URLs are both wrong.
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')))
                ?: ['127.0.0.1', '::1'],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
