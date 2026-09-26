<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoadTestSeedPlayersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces SESSION_DRIVER=array for test speed/isolation —
        // sensible everywhere else, but the command resolves whichever
        // handler the app is actually configured to use, and the array
        // driver never persists anywhere a generated cookie could later be
        // read back from. Force the database driver so these tests
        // exercise a real persisted store instead.
        config(['session.driver' => 'database']);
    }

    /**
     * The real point of this command: a cookie it generates has to
     * authenticate a genuine request through the app's actual middleware
     * stack, exactly like a real login would — not just decrypt cleanly
     * in isolation. This test drives the command, then uses that cookie
     * on a real protected route, the same way k6 will.
     */
    public function test_a_generated_session_cookie_authenticates_a_real_request(): void
    {
        $csvPath = storage_path('app/testing-loadtest-sessions.csv');

        Artisan::call('loadtest:seed-players', [
            'count' => 2,
            '--output' => $csvPath,
            '--force' => true,
        ]);

        $this->assertFileExists($csvPath);
        $rows = array_map('str_getcsv', file($csvPath));
        array_shift($rows); // header
        [$username, $userId, $cookieValue, $csrfToken] = $rows[0];

        $this->assertSame('loadtest_player_1', $username);
        $this->assertSame((string) User::where('username', 'loadtest_player_1')->value('id'), $userId);
        $this->assertNotEmpty($csrfToken);

        // withCookie() would encrypt this value again — it's already the
        // fully-encrypted cookie the command produced, so this bypasses the
        // test client's own auto-encryption. And unlike a real HTTP
        // request, the test client never parses a raw wire-format Cookie
        // header (it builds the simulated request's cookie array directly
        // in PHP), so the value it wants is the CSV's stored form after
        // undoing the URL-encoding a real browser's transport would have —
        // not the still-encoded CSV value itself.
        $response = $this->withUnencryptedCookie(config('session.cookie'), rawurldecode($cookieValue))
            ->get(route('play.wallet.index'));

        $response->assertOk();
        $response->assertSee('1,000,000.00');

        unlink($csvPath);
    }

    /**
     * The whole point of shipping csrf_token alongside the cookie: a k6 VU
     * needs it for the POST /play/fight/{fight}/bet request without any
     * preliminary GET to scrape it from a page — this proves the token the
     * command hands out is the one this exact session actually accepts.
     */
    public function test_the_csrf_token_works_for_a_real_post_request(): void
    {
        $csvPath = storage_path('app/testing-loadtest-sessions-csrf.csv');
        Artisan::call('loadtest:seed-players', ['count' => 1, '--output' => $csvPath, '--force' => true]);

        $rows = array_map('str_getcsv', file($csvPath));
        array_shift($rows);
        [, , $cookieValue, $csrfToken] = $rows[0];

        $response = $this->withUnencryptedCookie(config('session.cookie'), rawurldecode($cookieValue))
            ->post(route('play.cash.withdraw'), ['_token' => $csrfToken]);

        // Whatever the withdrawal logic itself decides (no balance to
        // withdraw yet, etc.) is irrelevant here — a 419 means the CSRF
        // token was rejected, which is the one failure mode this test
        // exists to catch.
        $this->assertNotEquals(419, $response->getStatusCode());

        unlink($csvPath);
    }

    public function test_it_creates_players_with_the_player_role_and_the_requested_balance(): void
    {
        Artisan::call('loadtest:seed-players', [
            'count' => 3,
            '--balance' => 500,
            '--output' => storage_path('app/testing-loadtest-sessions-2.csv'),
            '--force' => true,
        ]);

        $this->assertSame(3, User::where('username', 'like', 'loadtest_player_%')->count());

        $player = User::where('username', 'loadtest_player_2')->first();
        $this->assertTrue($player->hasRole('player'));
        $this->assertEquals(500, (float) $player->wallet->main_balance);

        unlink(storage_path('app/testing-loadtest-sessions-2.csv'));
    }

    public function test_running_it_again_with_a_smaller_count_does_not_duplicate_or_remove_existing_accounts(): void
    {
        $out = storage_path('app/testing-loadtest-sessions-3.csv');

        Artisan::call('loadtest:seed-players', ['count' => 5, '--output' => $out, '--force' => true]);
        Artisan::call('loadtest:seed-players', ['count' => 2, '--output' => $out, '--force' => true]);

        // firstOrCreate by username — re-running never duplicates existing rows.
        $this->assertSame(5, User::where('username', 'like', 'loadtest_player_%')->count());

        unlink($out);
    }

    public function test_cleanup_removes_every_loadtest_account_and_nothing_else(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $realPlayer = User::factory()->create(['username' => 'a_real_player']);
        $realPlayer->assignRole('player');

        Artisan::call('loadtest:seed-players', [
            'count' => 3,
            '--output' => storage_path('app/testing-loadtest-sessions-4.csv'),
            '--force' => true,
        ]);

        $this->assertSame(3, User::where('username', 'like', 'loadtest_player_%')->count());

        Artisan::call('loadtest:cleanup', ['--force' => true]);

        $this->assertSame(0, User::where('username', 'like', 'loadtest_player_%')->count());
        $this->assertTrue(User::where('id', $realPlayer->id)->exists());

        unlink(storage_path('app/testing-loadtest-sessions-4.csv'));
    }
}
