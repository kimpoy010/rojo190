<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['player', 'teller', 'declarator', 'superadmin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['password' => Hash::make('original-password')]);
        $user->assignRole($role);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_a_logged_in_user_can_change_their_password_without_the_current_one(): void
    {
        $user = $this->userWithRole('player');

        $response = $this->actingAs($user)->put(route('account.password.update'), [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertRedirect(route('account.password.edit'));
        $response->assertSessionHas('success');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $user = $this->userWithRole('player');

        $response = $this->actingAs($user)->put(route('account.password.update'), [
            'password' => 'brand-new-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_minimum_length(): void
    {
        $user = $this->userWithRole('player');

        $response = $this->actingAs($user)->put(route('account.password.update'), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_a_guest_cannot_reach_the_password_change_page(): void
    {
        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'), [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('login'));
    }

    /**
     * Not scoped to any one role — every role reaches it through the
     * shared account.* routes, not a role-prefixed group.
     */
    public function test_every_role_can_reach_the_password_change_page(): void
    {
        foreach (['player', 'teller', 'declarator', 'superadmin', 'agent'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get(route('account.password.edit'))->assertOk();
        }
    }
}
