<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cloudflare Turnstile on login/register (see App\Rules\Turnstile). The
 * rule is a no-op unless TURNSTILE_SECRET_KEY is configured, so every
 * test here sets it explicitly rather than relying on env — every other
 * test in the suite runs with it unset, which is also implicitly
 * exercised by every other test that logs a user in via ->post(route(
 * 'login'), ...) without a cf-turnstile-response field.
 */
class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        config(['services.turnstile.secret_key' => 'test-secret']);
    }

    private function fakeTurnstile(bool $success): void
    {
        Http::fake([
            'challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => $success]),
        ]);
    }

    public function test_login_succeeds_when_turnstile_verifies(): void
    {
        $this->fakeTurnstile(true);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('player');
        Wallet::create(['user_id' => $user->id]);

        $response = $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'password',
            'cf-turnstile-response' => 'a-valid-looking-token',
        ]);

        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['response'] === 'a-valid-looking-token');
    }

    public function test_login_fails_when_turnstile_rejects_the_token(): void
    {
        $this->fakeTurnstile(false);
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('player');
        Wallet::create(['user_id' => $user->id]);

        $response = $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'password',
            'cf-turnstile-response' => 'forged-token',
        ]);

        $response->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();
    }

    public function test_login_fails_when_no_turnstile_token_is_submitted(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('player');
        Wallet::create(['user_id' => $user->id]);

        $response = $this->post(route('login'), [
            'login' => $user->username,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_register_succeeds_when_turnstile_verifies(): void
    {
        $this->fakeTurnstile(true);

        $response = $this->post(route('register'), [
            'name' => 'New Player',
            'username' => 'newplayer',
            'email' => 'newplayer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'cf-turnstile-response' => 'a-valid-looking-token',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'newplayer']);
    }

    public function test_register_fails_when_turnstile_rejects_the_token(): void
    {
        $this->fakeTurnstile(false);

        $response = $this->post(route('register'), [
            'name' => 'New Player',
            'username' => 'newplayer',
            'email' => 'newplayer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'cf-turnstile-response' => 'forged-token',
        ]);

        $response->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'newplayer']);
    }
}
