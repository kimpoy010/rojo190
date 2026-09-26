<?php

namespace App\Http\Middleware;

use App\Models\Game;
use App\Support\GameTheme;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * English/Spanish only. An explicit switcher choice (session('locale'))
 * always wins; otherwise the pool-sabong game's region picks the default —
 * Mexico defaults to Spanish, everything else to English — so a fresh
 * session (including a no-login kiosk screen) still shows the language
 * that actually matches the region without anyone having to touch the
 * switcher.
 *
 * The app is single-tenant (one pool-sabong Game row), so this same
 * region lookup also decides the currency symbol for every page — player
 * and staff alike — that isn't already tied to a specific event/game and
 * so can't pull it from `$event->game->theme()['currency']` itself.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $region = Game::where('game_name', 'pool-sabong')->value('region');

        $locale = $request->session()->get('locale');
        if (! in_array($locale, ['en', 'es'], true)) {
            $locale = $region === 'mexico' ? 'es' : 'en';
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        View::share('currencySymbol', GameTheme::currencySymbol($region));

        return $next($request);
    }
}
