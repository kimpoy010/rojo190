<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Services\BettingService;
use App\Services\FightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LoadTestReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlayers(int $count, float $balance = 1000): void
    {
        Artisan::call('loadtest:seed-players', [
            'count' => $count,
            '--balance' => $balance,
            '--output' => storage_path('app/testing-loadtest-report.csv'),
            '--force' => true,
        ]);
        @unlink(storage_path('app/testing-loadtest-report.csv'));
    }

    private function fight(): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_it_fails_loudly_when_there_are_no_loadtest_accounts(): void
    {
        $this->artisan('loadtest:report')->assertExitCode(1);
    }

    public function test_it_reports_zero_bets_and_a_clean_reconciliation_when_nothing_was_bet(): void
    {
        $this->seedPlayers(2, 1000);

        $outputPath = storage_path('app/testing-loadtest-report-out.json');
        $this->artisan('loadtest:report', ['--starting-balance' => 1000, '--output' => $outputPath])
            ->assertExitCode(0);

        $report = json_decode(file_get_contents($outputPath), true);

        $this->assertSame(2, $report['accounts']);
        $this->assertSame(0, $report['bets']['total']);
        $this->assertSame([], $report['wallet_reconciliation']['mismatches']);

        unlink($outputPath);
    }

    public function test_it_reconciles_a_settled_bet_correctly(): void
    {
        $this->seedPlayers(1, 1000);
        $player = User::where('username', 'loadtest_player_1')->first();

        $fight = $this->fight();
        app(BettingService::class)->placeBet($player, $fight, 'meron', 100);

        // Opposing stake so meron actually wins something back.
        $otherBettor = User::factory()->create();
        \App\Models\Wallet::create(['user_id' => $otherBettor->id, 'main_balance' => 1000]);
        app(BettingService::class)->placeBet($otherBettor, $fight, 'wala', 100);

        $fight->update(['status' => 'closed']);
        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        $outputPath = storage_path('app/testing-loadtest-report-settled.json');
        $this->artisan('loadtest:report', ['--starting-balance' => 1000, '--output' => $outputPath])
            ->assertExitCode(0);

        $report = json_decode(file_get_contents($outputPath), true);

        $this->assertSame(1, $report['bets']['total']);
        $this->assertSame([], $report['wallet_reconciliation']['mismatches']);
        $this->assertGreaterThan(0, $report['bets']['total_paid_out']);

        unlink($outputPath);
    }

    public function test_it_flags_a_wallet_that_does_not_match_the_bet_ledger(): void
    {
        $this->seedPlayers(1, 1000);
        $player = User::where('username', 'loadtest_player_1')->first();

        $fight = $this->fight();
        app(BettingService::class)->placeBet($player, $fight, 'meron', 100);

        // Corrupt the wallet directly — the ledger says it should be down
        // 100 (balance 900) but it isn't, simulating a lost/duplicated
        // write under load.
        $player->wallet->update(['main_balance' => 1000]);

        $outputPath = storage_path('app/testing-loadtest-report-mismatch.json');
        $exitCode = Artisan::call('loadtest:report', ['--starting-balance' => 1000, '--output' => $outputPath]);

        $this->assertSame(1, $exitCode);

        $report = json_decode(file_get_contents($outputPath), true);
        $this->assertCount(1, $report['wallet_reconciliation']['mismatches']);
        $this->assertSame('loadtest_player_1', $report['wallet_reconciliation']['mismatches'][0]['username']);

        unlink($outputPath);
    }
}
