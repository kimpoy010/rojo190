<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;

/**
 * Prepares player accounts for a k6 load test — and, critically, an
 * already-authenticated session cookie for each one, generated the same
 * way Laravel itself would (real Store + real SessionGuard + the app's
 * real APP_KEY), without ever going through POST /login. That route is
 * throttled 10/min per IP (see routes/web.php) specifically to block
 * credential stuffing; every simulated bettor logging in live from one
 * k6 VPS's single IP would slam straight into that limit and make the
 * whole test look like a catastrophic failure that's actually just this
 * app's own anti-brute-force protection working as intended.
 *
 * Usage:
 *   php artisan loadtest:seed-players 5000 --balance=1000000 --output=storage/app/loadtest-sessions.csv
 *
 * The output CSV (username,user_id,session_cookie,csrf_token) is what k6
 * reads — one row per virtual user, cookie already valid and its matching
 * CSRF token alongside it, so a VU can go straight to POSTing a bet with no
 * login step and no preliminary GET to scrape a token from the page.
 * user_id is included for anything that needs to address this player's own
 * private channel directly (e.g. subscribing to `wallet.{user_id}` to
 * measure broadcast latency), since the channel name isn't derivable from
 * the cookie without decrypting it.
 */
class LoadTestSeedPlayers extends Command
{
    use ConfirmableTrait;

    protected $signature = 'loadtest:seed-players
        {count=100 : How many test player accounts to prepare}
        {--balance=1000000 : Starting wallet balance for each, in the app\'s currency}
        {--output= : Where to write the CSV (defaults to storage/app/loadtest-sessions.csv)}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Create (or refresh) load-test player accounts with a pre-authenticated session each, for a k6 run';

    public function handle(): int
    {
        if (! $this->confirmToProceed('This creates load-test accounts and sessions directly in the production database.')) {
            return self::FAILURE;
        }

        $count = (int) $this->argument('count');
        $balance = (float) $this->option('balance');
        $outputPath = $this->option('output') ?: storage_path('app/loadtest-sessions.csv');

        if ($count < 1) {
            $this->error('count must be at least 1.');

            return self::FAILURE;
        }

        Role::firstOrCreate(['name' => 'player']);

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, ['username', 'user_id', 'session_cookie', 'csrf_token']);

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 1; $i <= $count; $i++) {
            $username = "loadtest_player_{$i}";

            $user = User::firstOrCreate(
                ['username' => $username],
                [
                    'name' => "Load Test Player {$i}",
                    'email' => "{$username}@loadtest.invalid",
                    'password' => bcrypt(bin2hex(random_bytes(16))), // never logged in with, just has to exist
                    'email_verified_at' => now(),
                ]
            );

            if (! $user->hasRole('player')) {
                $user->assignRole('player');
            }

            $wallet = $user->wallet ?? Wallet::create(['user_id' => $user->id]);
            $wallet->update(['main_balance' => $balance]);

            [$cookie, $csrfToken] = $this->authenticatedSession($user);
            fputcsv($handle, [$username, $user->id, $cookie, $csrfToken]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        fclose($handle);

        $this->info("Wrote {$count} sessions to {$outputPath}");
        $this->warn('These accounts are clearly named (loadtest_player_*) and use fake @loadtest.invalid emails — run `php artisan loadtest:cleanup` afterward to remove them and everything they did (bets, wallet transactions cascade on delete).');

        return self::SUCCESS;
    }

    /**
     * Builds a real session — same Store/handler/serialization Laravel
     * uses for a normal request — logs the user into it via a standalone
     * SessionGuard (bypassing the shared 'session'/'auth' singletons,
     * since those are bound to the current console request, not to any
     * of these per-user sessions), persists it to whichever store this
     * app is actually configured to use, and returns [cookie value, CSRF
     * token]. The cookie is built exactly as EncryptCookies middleware
     * would have produced it — the app's cookie-value-prefix, then
     * encrypted with the real APP_KEY — so a request presenting it is
     * indistinguishable from one that actually logged in normally.
     *
     * @return array{0: string, 1: string}
     */
    private function authenticatedSession(User $user): array
    {
        // Resolved from the app's own session manager rather than
        // hardcoded to DatabaseSessionHandler — this app runs
        // SESSION_DRIVER=redis in production, and a session persisted to
        // the sessions table would be invisible to the real app (which
        // never looks there), leaving every generated cookie silently
        // unauthenticated against a live request.
        $handler = $this->laravel['session']->driver()->getHandler();

        // Must match how SessionManager actually builds a Store for a real
        // request (see SessionManager::buildSession()) — this app has
        // session.serialization set to 'json' (Laravel's modern default,
        // safer against PHP object-injection than 'php' serialize()), and
        // omitting it here silently defaults back to 'php', producing a
        // session payload the real app can't read back.
        $store = new Store(config('session.cookie'), $handler, null, config('session.serialization', 'php'));
        $store->start(); // also generates this session's _token

        $provider = Auth::createUserProvider(config('auth.guards.web.provider'));
        $guard = new SessionGuard('web', $provider, $store);
        $guard->login($user);

        $store->save();

        $cookieName = config('session.cookie');
        $prefix = hash_hmac('sha1', $cookieName.'v2', Crypt::getKey()).'|';
        $cookie = rawurlencode(Crypt::encrypt($prefix.$store->getId(), false));

        return [$cookie, $store->token()];
    }
}
