<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'teller']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        return $admin;
    }

    public function test_a_superadmin_can_view_the_audit_trail(): void
    {
        $admin = $this->admin();
        AuditLogger::log('test.action', 'Something happened.', actor: $admin);

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index'));

        $response->assertOk();
        $response->assertSee('Something happened.');
        $response->assertSee('test.action');
    }

    public function test_filtering_by_username_only_shows_that_actors_entries(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create(['username' => 'the_teller']);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        AuditLogger::log('test.action', 'By admin.', actor: $admin);
        AuditLogger::log('test.action', 'By teller.', actor: $teller);

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index', ['username' => $teller->displayName()]));

        $response->assertOk();
        $response->assertSee('By teller.');
        $response->assertDontSee('By admin.');
    }

    public function test_filtering_by_action_only_shows_matching_entries(): void
    {
        $admin = $this->admin();

        AuditLogger::log('cash.deposit_requested', 'A deposit request.', actor: $admin);
        AuditLogger::log('fight.declared', 'A fight declared.', actor: $admin);

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index', ['action' => 'fight.declared']));

        $response->assertOk();
        $response->assertSee('A fight declared.');
        $response->assertDontSee('A deposit request.');
    }

    public function test_the_date_range_filters_entries(): void
    {
        $admin = $this->admin();

        $old = AuditLogger::log('test.action', 'Old entry.', actor: $admin);
        $old->created_at = now()->subDays(10);
        $old->saveQuietly();

        AuditLogger::log('test.action', 'Recent entry.', actor: $admin);

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index', [
            'date_from' => now()->subDays(2)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Recent entry.');
        $response->assertDontSee('Old entry.');
    }

    public function test_pagination_is_twenty_per_page(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 25; $i++) {
            AuditLogger::log('test.action', "Entry {$i}.", actor: $admin);
        }

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index'));

        $response->assertOk();
        $logs = $response->viewData('logs');
        $this->assertSame(20, $logs->perPage());
        $this->assertCount(20, $logs->items());
        $this->assertTrue($logs->hasMorePages());
    }

    public function test_a_non_superadmin_cannot_view_the_audit_trail(): void
    {
        $admin = $this->admin();
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $response = $this->actingAs($teller)->get(route('superadmin.audit.index'));

        $response->assertForbidden();
    }

    public function test_placing_a_bet_writes_an_audit_entry_visible_on_the_page(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $admin = $this->admin();
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = \App\Models\Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = \App\Models\Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = \App\Models\Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        app(\App\Services\BettingService::class)->placeBet($player, $fight, 'meron', 20);

        $response = $this->actingAs($admin)->get(route('superadmin.audit.index'));

        $response->assertOk();
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertSee('wallet.bet');
    }
}
