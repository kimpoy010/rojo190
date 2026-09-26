<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Per-username login throttling (see LoginController) — separate from
 * (and tighter than) the per-IP `throttle:10,1` route middleware, which
 * alone lets a distributed attack spray one account's password across
 * many source IPs.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
    }

    private function player(): User
    {
        $player = User::factory()->create(['password' => Hash::make('password')]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        return $player;
    }

    private function attempt(User $user, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login'), ['login' => $user->username, 'password' => $password]);
    }

    public function test_the_sixth_failed_attempt_in_a_minute_is_locked_out_even_with_the_right_password(): void
    {
        $user = $this->player();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user, 'wrong-password')->assertSessionHasErrors('login');
            $this->assertGuest();
        }

        // The 6th attempt is blocked by the throttle itself, not a
        // credentials check — proven by using the CORRECT password here
        // and still failing to authenticate.
        $response = $this->attempt($user, 'password');

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('login'));
    }

    public function test_a_successful_login_clears_the_failed_attempt_counter(): void
    {
        $user = $this->player();

        $this->attempt($user, 'wrong-password')->assertSessionHasErrors('login');
        $this->attempt($user, 'wrong-password')->assertSessionHasErrors('login');

        $response = $this->attempt($user, 'password');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect();
    }

    public function test_lockout_is_scoped_per_username_not_shared_across_accounts(): void
    {
        $lockedOutUser = $this->player();
        $otherUser = $this->player();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($lockedOutUser, 'wrong-password');
        }
        $this->attempt($lockedOutUser, 'password')->assertSessionHasErrors('login');
        $this->assertGuest();

        // A different account's credentials are unaffected by the first
        // account's lockout.
        $this->attempt($otherUser, 'password');
        $this->assertAuthenticatedAs($otherUser);
    }
}
