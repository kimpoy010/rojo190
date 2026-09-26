<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Rules\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Separate from (and tighter than) the per-IP `throttle:10,1`
     * middleware on this route — that axis alone lets a distributed
     * attack spray one specific account's password across many IPs, each
     * getting its own fresh 10/min budget. This one's keyed by the
     * submitted username/email instead, so it catches that regardless of
     * how many source IPs are involved.
     */
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
            'cf-turnstile-response' => Turnstile::rules(),
        ]);

        $throttleKey = 'login:'.Str::lower($credentials['login']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'login' => trans_choice(
                    'Too many login attempts. Please try again in :seconds second.|Too many login attempts. Please try again in :seconds seconds.',
                    $seconds,
                    ['seconds' => $seconds]
                ),
            ])->onlyInput('login');
        }

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$field => $credentials['login'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            return back()->withErrors(['login' => __('Those credentials do not match our records.')])->onlyInput('login');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended($this->landingUrl(Auth::user()));
    }

    /**
     * Where a fresh login lands, absent an "intended" URL (e.g. they hit a
     * protected page while logged out and got bounced here first). A
     * player skips the events list entirely and goes straight to whatever
     * fight is currently in play — the first live event, by the same
     * ordering the events list itself uses, and its latest (current)
     * fight. Falls back to the plain events list if nothing is live yet,
     * or straight through to every other role's own home route.
     */
    private function landingUrl(User $user): string
    {
        if (! $user->hasRole('player')) {
            return route($user->homeRouteName());
        }

        $event = Event::where('status', 'live')->orderBy('date')->first();
        $fight = $event?->currentFight()->first();

        return $fight ? route('play.pool-fight', $fight) : route('play.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
