<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports on what actually happened to loadtest_player_* accounts during a
 * k6 run — a passing HTTP status code from k6 only means the request came
 * back with a 2xx, not that the bet was recorded correctly or the wallet
 * balance is right. For a betting app, that gap matters more than raw
 * request timing, so this checks it directly against the database instead
 * of trusting k6's own numbers alone.
 *
 * Reconciliation follows the same staked/paid-out accounting convention
 * used everywhere else in this app (see AccountingController,
 * IncomeReportController): a voided bet is excluded entirely (its stake
 * was handed straight back, so debit and refund cancel out); a refunded
 * bet has no `payout` column set, so its own `amount` stands in for the
 * refund; every other non-voided bet's `payout` column is used as-is
 * (still null/0 for one that hasn't settled yet).
 */
class LoadTestReport extends Command
{
    protected $signature = 'loadtest:report
        {--since= : Only consider bets placed after this datetime (e.g. "2026-09-19 14:00:00") — useful if you ran multiple tests without cleaning up between them}
        {--starting-balance=1000000 : The --balance every account was seeded with (must match what you passed to loadtest:seed-players)}
        {--output= : Where to write the JSON report (defaults to storage/app/loadtest-report.json)}';

    protected $description = 'Report on bets placed and wallet reconciliation for loadtest_player_* accounts after a k6 run';

    public function handle(): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;
        $startingBalance = (float) $this->option('starting-balance');
        $outputPath = $this->option('output') ?: storage_path('app/loadtest-report.json');

        $users = User::where('username', 'like', 'loadtest_player_%')->with('wallet')->get();

        if ($users->isEmpty()) {
            $this->error('No loadtest_player_* accounts found — did loadtest:seed-players run, and has loadtest:cleanup already removed them?');

            return self::FAILURE;
        }

        $betsQuery = Bet::whereIn('user_id', $users->pluck('id'))
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since));

        $byStatus = (clone $betsQuery)
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as staked')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $totalBets = $byStatus->sum('count');
        $totalStaked = (float) (clone $betsQuery)->where('status', '!=', 'voided')->sum('amount');
        $totalPaidOut = (float) (clone $betsQuery)
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN amount ELSE COALESCE(payout, 0) END) as total")
            ->where('status', '!=', 'voided')
            ->value('total');

        // Per-user wallet reconciliation: does main_balance actually match
        // what it should be given every non-voided bet this account placed?
        $mismatches = [];
        foreach ($users as $user) {
            $userBets = (clone $betsQuery)->where('user_id', $user->id)->get();

            $expected = $startingBalance
                - (float) $userBets->where('status', '!=', 'voided')->sum('amount')
                + (float) $userBets->sum(fn (Bet $b) => $b->status === 'refunded'
                    ? (float) $b->amount
                    : ($b->status !== 'voided' ? (float) ($b->payout ?? 0) : 0));

            $actual = (float) ($user->wallet->main_balance ?? 0);

            if (abs($expected - $actual) >= 0.01) {
                $mismatches[] = [
                    'username' => $user->username,
                    'expected' => round($expected, 2),
                    'actual' => round($actual, 2),
                    'diff' => round($actual - $expected, 2),
                ];
            }
        }

        // Queue failures aren't scoped to loadtest jobs specifically (the
        // failed_jobs table doesn't tag which user/run a job belonged to),
        // but a spike here during the test window is still a real signal —
        // WalletBalanceUpdated broadcasts silently failing under load is
        // exactly the kind of thing an HTTP-only k6 report can't catch.
        $failedJobsQuery = DB::table('failed_jobs')->when($since, fn ($q) => $q->where('failed_at', '>=', $since));
        $failedJobs = $failedJobsQuery->count();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'since' => $since?->toIso8601String(),
            'accounts' => $users->count(),
            'bets' => [
                'total' => $totalBets,
                'by_status' => $byStatus->map(fn ($row) => ['count' => (int) $row->count, 'staked' => (float) $row->staked])->toArray(),
                'total_staked' => round($totalStaked, 2),
                'total_paid_out' => round($totalPaidOut, 2),
            ],
            'wallet_reconciliation' => [
                'accounts_checked' => $users->count(),
                'mismatches' => $mismatches,
            ],
            'failed_jobs_in_window' => $failedJobs,
        ];

        file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT));

        $this->info("Accounts: {$users->count()}");
        $this->info("Bets placed: {$totalBets}");
        foreach ($byStatus as $status => $row) {
            $this->line("  {$status}: {$row->count} (\${$row->staked})");
        }
        $this->info('Total staked (excl. voided): $'.number_format($totalStaked, 2));
        $this->info('Total paid out: $'.number_format($totalPaidOut, 2));
        $this->info("Failed queue jobs in window: {$failedJobs}");

        if (empty($mismatches)) {
            $this->info('Wallet reconciliation: all '.$users->count().' accounts match expected balance.');
        } else {
            $this->error(count($mismatches).' account(s) have a wallet balance that doesn\'t match what the bet ledger says it should be:');
            $this->table(['Username', 'Expected', 'Actual', 'Diff'], $mismatches);
        }

        $this->newLine();
        $this->info("Full report written to {$outputPath}");

        return empty($mismatches) ? self::SUCCESS : self::FAILURE;
    }
}
