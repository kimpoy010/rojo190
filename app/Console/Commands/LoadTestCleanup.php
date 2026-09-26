<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Removes every account loadtest:seed-players created — bets, wallet,
 * and wallet_transactions all cascade-delete with the user (see the
 * foreignId()->cascadeOnDelete() constraints on those tables), so
 * deleting the users is enough to fully undo the load test's footprint.
 * Their sessions age out on their own (session.lifetime) and aren't
 * worth cleaning up separately.
 */
class LoadTestCleanup extends Command
{
    use ConfirmableTrait;

    protected $signature = 'loadtest:cleanup {--force : Skip the production confirmation prompt}';

    protected $description = 'Delete every loadtest_player_* account created by loadtest:seed-players, and everything that cascades from it';

    public function handle(): int
    {
        $query = User::where('username', 'like', 'loadtest_player_%');
        $count = $query->count();

        if ($count === 0) {
            $this->info('No loadtest_player_* accounts found — nothing to clean up.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed("This permanently deletes {$count} load-test accounts and everything that cascades from them (bets, wallet, wallet transactions) from the production database.")) {
            return self::FAILURE;
        }

        $query->each(fn (User $user) => $user->delete());

        $this->info("Deleted {$count} load-test accounts.");

        return self::SUCCESS;
    }
}
