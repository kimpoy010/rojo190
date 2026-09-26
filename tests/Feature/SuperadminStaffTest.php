<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminStaffTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'declarator']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        return $admin;
    }

    public function test_a_superadmin_can_create_a_teller_account(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('superadmin.staff.store'), [
            'role' => 'teller',
            'name' => 'New Teller',
            'username' => 'new_teller',
            'email' => 'new-teller@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('superadmin.staff.index'));

        $teller = User::where('username', 'new_teller')->firstOrFail();
        $this->assertTrue($teller->hasRole('teller'));
        $this->assertFalse($teller->hasRole('declarator'));
        $this->assertNotNull($teller->wallet);
    }

    public function test_a_superadmin_can_create_a_declarator_account(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('superadmin.staff.store'), [
            'role' => 'declarator',
            'name' => 'New Declarator',
            'username' => 'new_declarator',
            'email' => 'new-declarator@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('superadmin.staff.index'));

        $declarator = User::where('username', 'new_declarator')->firstOrFail();
        $this->assertTrue($declarator->hasRole('declarator'));
        $this->assertFalse($declarator->hasRole('teller'));
    }

    public function test_the_created_account_can_log_in_and_reach_its_role_area(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('superadmin.staff.store'), [
            'role' => 'teller',
            'name' => 'Login Teller',
            'username' => 'login_teller',
            'email' => 'login-teller@example.com',
            'password' => 'password123',
        ]);

        $teller = User::where('username', 'login_teller')->firstOrFail();

        // No shift open yet — the dashboard itself redirects to start one,
        // which is expected; what matters here is the account can actually
        // authenticate into the teller area at all, not just that the role
        // was assigned in the database.
        $response = $this->actingAs($teller)->get(route('teller.dashboard'));
        $response->assertRedirect(route('teller.shift.start'));

        $this->actingAs($teller)->get(route('teller.shift.start'))->assertOk();
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('superadmin.staff.store'), [
            'role' => 'superadmin',
            'name' => 'Sneaky',
            'username' => 'sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertNull(User::where('username', 'sneaky')->first());
    }

    public function test_a_duplicate_username_is_rejected(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['username' => 'taken_name']);
        $existing->assignRole('teller');
        Wallet::create(['user_id' => $existing->id]);

        $response = $this->actingAs($admin)->post(route('superadmin.staff.store'), [
            'role' => 'declarator',
            'name' => 'Duplicate',
            'username' => 'taken_name',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('username');
    }

    public function test_the_index_lists_only_tellers_and_declarators(): void
    {
        $admin = $this->admin();
        Role::firstOrCreate(['name' => 'player']);

        $teller = User::factory()->create(['username' => 'the_teller']);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $declarator = User::factory()->create(['username' => 'the_declarator']);
        $declarator->assignRole('declarator');
        Wallet::create(['user_id' => $declarator->id]);

        $player = User::factory()->create(['username' => 'the_player']);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $response = $this->actingAs($admin)->get(route('superadmin.staff.index'));

        $response->assertOk();
        $response->assertSee('the_teller');
        $response->assertSee('the_declarator');
        $response->assertDontSee('the_player');
    }

    public function test_a_non_superadmin_cannot_reach_the_staff_pages(): void
    {
        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'declarator']);
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($teller)->get(route('superadmin.staff.index'))->assertForbidden();
        $this->actingAs($teller)->get(route('superadmin.staff.create'))->assertForbidden();
        $this->actingAs($teller)->post(route('superadmin.staff.store'), [
            'role' => 'declarator', 'name' => 'X', 'username' => 'x', 'email' => 'x@example.com', 'password' => 'password123',
        ])->assertForbidden();
        $this->actingAs($teller)->get(route('superadmin.staff.edit', $teller))->assertForbidden();
        $this->actingAs($teller)->put(route('superadmin.staff.update', $teller), [])->assertForbidden();
        $this->actingAs($teller)->post(route('superadmin.staff.toggle-status', $teller))->assertForbidden();
    }

    public function test_a_superadmin_can_edit_a_staff_members_details(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create(['name' => 'Old Name', 'username' => 'old_username', 'email' => 'old@example.com']);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $response = $this->actingAs($admin)->put(route('superadmin.staff.update', $teller), [
            'role' => 'teller',
            'name' => 'New Name',
            'username' => 'new_username',
            'email' => 'new@example.com',
            'password' => '',
        ]);

        $response->assertRedirect(route('superadmin.staff.index'));
        $teller->refresh();
        $this->assertSame('New Name', $teller->name);
        $this->assertSame('new_username', $teller->username);
        $this->assertSame('new@example.com', $teller->email);
    }

    public function test_editing_can_switch_the_role_between_teller_and_declarator(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($admin)->put(route('superadmin.staff.update', $teller), [
            'role' => 'declarator',
            'name' => $teller->name,
            'username' => $teller->username,
            'email' => $teller->email,
            'password' => '',
        ]);

        $teller->refresh();
        $this->assertTrue($teller->hasRole('declarator'));
        $this->assertFalse($teller->hasRole('teller'));
    }

    public function test_a_blank_password_on_edit_keeps_the_existing_password(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create(['password' => bcrypt('original-password')]);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);
        $originalHash = $teller->password;

        $this->actingAs($admin)->put(route('superadmin.staff.update', $teller), [
            'role' => 'teller',
            'name' => $teller->name,
            'username' => $teller->username,
            'email' => $teller->email,
            'password' => '',
        ]);

        $this->assertSame($originalHash, $teller->fresh()->password);
    }

    public function test_a_provided_password_on_edit_changes_it(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create(['password' => bcrypt('original-password')]);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($admin)->put(route('superadmin.staff.update', $teller), [
            'role' => 'teller',
            'name' => $teller->name,
            'username' => $teller->username,
            'email' => $teller->email,
            'password' => 'brand-new-password',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brand-new-password', $teller->fresh()->password));
    }

    public function test_a_superadmin_can_deactivate_and_reactivate_a_staff_member(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($admin)->post(route('superadmin.staff.toggle-status', $teller))->assertRedirect();
        $this->assertSame('inactive', $teller->fresh()->status);

        $this->actingAs($admin)->post(route('superadmin.staff.toggle-status', $teller))->assertRedirect();
        $this->assertSame('active', $teller->fresh()->status);
    }

    public function test_a_deactivated_staff_member_is_signed_out_on_their_next_request(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($admin)->post(route('superadmin.staff.toggle-status', $teller));

        // actingAs() authenticates using this exact in-memory model, so it
        // must be refreshed first — otherwise the guard would carry the
        // stale (still-active) status the variable was created with.
        $response = $this->actingAs($teller->refresh())->get(route('teller.dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_editing_a_non_staff_user_through_this_controller_is_rejected(): void
    {
        $admin = $this->admin();
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $this->actingAs($admin)->get(route('superadmin.staff.edit', $player))->assertStatus(422);
        $this->actingAs($admin)->put(route('superadmin.staff.update', $player), [
            'role' => 'teller', 'name' => 'X', 'username' => 'x2', 'email' => 'x2@example.com', 'password' => '',
        ])->assertStatus(422);
        $this->actingAs($admin)->post(route('superadmin.staff.toggle-status', $player))->assertStatus(422);
    }
}
