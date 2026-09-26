<?php

namespace App\Providers;

use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuditListeners();
    }

    /**
     * Listening to Laravel's own auth events (rather than instrumenting
     * LoginController directly) covers every guard/entry point the app
     * ever gains, not just the one login form that exists today.
     */
    private function registerAuditListeners(): void
    {
        Event::listen(Login::class, function (Login $event) {
            AuditLogger::log(
                action: 'auth.login',
                description: __(':name logged in.', ['name' => $event->user->displayName()]),
                target: $event->user,
                actor: $event->user,
            );
        });

        Event::listen(Logout::class, function (Logout $event) {
            if (! $event->user) {
                return;
            }

            AuditLogger::log(
                action: 'auth.logout',
                description: __(':name logged out.', ['name' => $event->user->displayName()]),
                target: $event->user,
                actor: $event->user,
            );
        });

        Event::listen(Failed::class, function (Failed $event) {
            // Never log the attempted password — only the identifier field
            // (email or username) LoginController authenticated against.
            $identifier = collect($event->credentials)->except('password')->values()->first();

            AuditLogger::log(
                action: 'auth.login_failed',
                description: __('Failed login attempt for ":login".', ['login' => $identifier ?? '?']),
            );
        });
    }
}
