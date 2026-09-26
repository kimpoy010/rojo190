<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminWalletsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_player_list_is_paginated_instead_of_loading_every_player(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'superadmin']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        // One more than a page's worth, so a second page must exist.
        User::factory()->count(26)->create()->each(function (User $player) {
            $player->assignRole('player');
            Wallet::create(['user_id' => $player->id, 'main_balance' => 100]);
        });

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.index'));

        $response->assertOk();
        $response->assertViewHas('users', function ($users) {
            return $users->count() === 25 && $users->total() === 26 && $users->hasMorePages();
        });
        $response->assertSee('href="'.route('superadmin.wallets.index', ['page' => 2]).'"', false);
    }

    public function test_an_ajax_search_request_returns_only_matching_rows_as_a_partial(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'superadmin']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        $match = User::factory()->create(['username' => 'zamboanga_zed']);
        $match->assignRole('player');
        Wallet::create(['user_id' => $match->id, 'main_balance' => 50]);

        $other = User::factory()->create(['username' => 'someone_else']);
        $other->assignRole('player');
        Wallet::create(['user_id' => $other->id, 'main_balance' => 50]);

        $response = $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('superadmin.wallets.index', ['q' => 'zamboanga']));

        $response->assertOk();
        $response->assertSee('zamboanga_zed');
        $response->assertDontSee('someone_else');
        $response->assertDontSee('<h1'); // the page chrome isn't part of the partial
    }

    public function test_each_row_links_to_that_players_transaction_history(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'superadmin']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        $player = User::factory()->create(['username' => 'target_player']);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 100]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.index'));

        $response->assertOk();
        $response->assertSee('href="'.route('superadmin.wallets.transactions', $player).'"', false);
    }

    public function test_each_row_has_credit_and_debit_buttons_wired_to_that_players_endpoints(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'superadmin']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        $player = User::factory()->create(['username' => 'target_player']);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 100]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.index'));

        $response->assertOk();
        $response->assertSee('data-url="'.route('superadmin.wallets.credit', $player).'"', false);
        $response->assertSee('data-url="'.route('superadmin.wallets.debit', $player).'"', false);
    }
}
