<?php

use App\Http\Middleware\AuthenticateRfidTerminal;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy (Nginx/Apache terminating TLS in front of
        // `php artisan serve` or php-fpm), Laravel otherwise sees every
        // request as plain HTTP and generates http:// URLs even on an
        // https:// site — the mixed-content errors this caused. Trusting
        // all proxies for the forwarded headers is safe here since the app
        // itself isn't meant to be reachable except through that proxy.
        $middleware->trustProxies(at: '*');

        // Applies to every response — web and api alike, including the
        // RFID scan endpoint and the /up health check.
        $middleware->append(SecureHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            CheckUserStatus::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'rfid.terminal' => AuthenticateRfidTerminal::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
