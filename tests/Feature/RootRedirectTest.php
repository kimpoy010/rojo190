<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression coverage for a redirect loop: "/" used to unconditionally
 * redirect to "/login" (Route::redirect), but Laravel's guest middleware
 * sends an already-authenticated user hitting "/login" straight back to
 * "/" (there's no dashboard/home route to fall back to) — bouncing forever
 * for anyone with an existing session. "/" must be auth-aware instead.
 */
class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['player', 'declarator', 'superadmin', 'agent', 'teller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_guest_hitting_root_lands_on_login_without_looping(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_hitting_root_never_redirects_back_to_login(): void
    {
        foreach (['player', 'declarator', 'superadmin', 'agent', 'teller'] as $role) {
            $user = $this->userWithRole($role);

            $response = $this->actingAs($user)->get('/');

            $response->assertRedirect(route($user->homeRouteName()));
            $this->assertNotSame(route('login'), route($user->homeRouteName()));
        }
    }

    public function test_authenticated_user_hitting_login_page_eventually_reaches_their_dashboard_not_a_loop(): void
    {
        $user = $this->userWithRole('player');

        // Laravel's guest middleware bounces an authenticated user away from
        // /login to "/" first (one hop), which then forwards them on to
        // their real dashboard (a second hop) — the regression this guards
        // against is that second hop looping back to /login instead.
        $response = $this->actingAs($user)->get('/login');
        $response->assertRedirect('/');

        $response = $this->followingRedirects()->actingAs($user)->get('/login');
        $response->assertOk();
        $response->assertViewIs('player.index');
    }
}
