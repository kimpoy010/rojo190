<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyHashChains;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_actor_action_target_and_changes(): void
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        $target = User::factory()->create();
        Wallet::create(['user_id' => $target->id]);

        $log = AuditLogger::log(
            action: 'test.action',
            description: 'A test action happened.',
            target: $target,
            changes: ['status' => ['old' => 'active', 'new' => 'inactive']],
            actor: $admin,
        );

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($admin->displayName(), $log->actor_name);
        $this->assertSame('test.action', $log->action);
        $this->assertSame('User', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame('A test action happened.', $log->description);
        $this->assertSame(['status' => ['old' => 'active', 'new' => 'inactive']], $log->changes);
        $this->assertNotNull($log->hash);
    }

    public function test_it_defaults_the_actor_to_the_authenticated_user(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $this->actingAs($player);

        $log = AuditLogger::log('test.action', 'Something happened.');

        $this->assertSame($player->id, $log->actor_user_id);
    }

    public function test_a_null_actor_falls_back_to_the_system_chain(): void
    {
        $log = AuditLogger::log('test.action', 'A system-triggered event.');

        $this->assertNull($log->actor_user_id);
        $this->assertSame('system', $log->hashChainScope());
    }

    public function test_entries_chain_sequentially_per_actor(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $first = AuditLogger::log('test.one', 'First.', actor: $player);
        $second = AuditLogger::log('test.two', 'Second.', actor: $player);

        $this->assertNull($first->previous_hash);
        $this->assertSame($first->hash, $second->previous_hash);
        $this->assertNotSame($first->hash, $second->hash);
    }

    public function test_different_actors_chain_independently(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $playerA = User::factory()->create();
        $playerA->assignRole('player');
        Wallet::create(['user_id' => $playerA->id]);
        $playerB = User::factory()->create();
        $playerB->assignRole('player');
        Wallet::create(['user_id' => $playerB->id]);

        AuditLogger::log('test.one', 'First.', actor: $playerA);
        $bFirst = AuditLogger::log('test.one', 'First for B.', actor: $playerB);

        $this->assertNull($bFirst->previous_hash);
    }

    public function test_the_ledger_verify_command_covers_audit_logs(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        AuditLogger::log('test.action', 'Untampered.', actor: $player);

        $this->artisan(VerifyHashChains::class)->assertExitCode(0);
    }

    public function test_tampering_with_an_audit_log_row_is_detected(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $log = AuditLogger::log('test.action', 'Original description.', actor: $player);

        // Bypass the model entirely — a raw update, like an attacker with
        // direct DB access, never touches the hash chain machinery.
        AuditLog::where('id', $log->id)->update(['description' => 'Tampered description.']);

        $this->artisan(VerifyHashChains::class)->assertExitCode(1);
    }

    public function test_a_deactivating_staff_action_writes_an_audit_entry_with_before_and_after_status(): void
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'teller']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->actingAs($admin)->post(route('superadmin.staff.toggle-status', $teller));

        $log = AuditLog::where('action', 'staff.deactivated')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($teller->id, $log->target_id);
        $this->assertSame(['old' => 'active', 'new' => 'inactive'], $log->changes['status']);
    }
}
