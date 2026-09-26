<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\CashTransaction;
use App\Models\ChainHead;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Recomputes every financial row's hash from its own current field values
 * and confirms it still matches what was stored at creation, and that each
 * row's previous_hash still matches the prior row's hash within its chain
 * — the two ways tampering shows up: a row edited in place, or a row
 * deleted/inserted/reordered out from under the app (direct DB access,
 * bypassing every model event this app itself would have gone through).
 *
 * Streams id-ascending in chunks rather than loading a table at once — a
 * chain's rows always arrive in that same relative order regardless of
 * which other scopes' rows interleave with them, so a small "last hash
 * seen" map per scope is all a single pass needs, however large the table.
 */
class VerifyHashChains extends Command
{
    protected $signature = 'ledger:verify {--chain= : Only verify one table (wallet_transactions, cash_transactions, bets, or audit_logs)}';

    protected $description = 'Verify every financial hash chain for tampering, deletion, or reordering';

    private const MODELS = [
        'wallet_transactions' => WalletTransaction::class,
        'cash_transactions' => CashTransaction::class,
        'bets' => Bet::class,
        'audit_logs' => AuditLog::class,
    ];

    public function handle(): int
    {
        $requested = $this->option('chain');

        if ($requested && ! isset(self::MODELS[$requested])) {
            $this->error("Unknown chain '{$requested}'. Expected one of: ".implode(', ', array_keys(self::MODELS)));

            return self::FAILURE;
        }

        $chains = $requested ? [$requested => self::MODELS[$requested]] : self::MODELS;
        $failures = 0;

        foreach ($chains as $chain => $modelClass) {
            $failures += $this->verifyChain($chain, $modelClass);
        }

        if ($failures === 0) {
            $this->info('All chains verified — no tampering detected.');

            return self::SUCCESS;
        }

        $this->error("{$failures} integrity failure(s) found.");

        return self::FAILURE;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function verifyChain(string $chain, string $modelClass): int
    {
        $this->line("Verifying {$chain}...");

        $failures = 0;
        $lastHashByScope = [];
        $rowCountByScope = [];

        $modelClass::query()->orderBy('id')->chunkById(500, function ($rows) use (&$failures, &$lastHashByScope, &$rowCountByScope, $chain, $modelClass) {
            foreach ($rows as $row) {
                $scope = $row->hashChainScope();
                $expectedPrevious = $lastHashByScope[$scope] ?? null;
                $rowCountByScope[$scope] = ($rowCountByScope[$scope] ?? 0) + 1;

                // A row created before hashing existed (or before a
                // backfill ran) has no hash at all — that's not evidence
                // of tampering, just data this chain doesn't cover yet.
                // Its scope's chain effectively starts fresh at the next
                // row that does have one.
                if ($row->hash === null) {
                    $lastHashByScope[$scope] = null;

                    continue;
                }

                if ($row->previous_hash !== $expectedPrevious) {
                    $this->error("  [{$chain}:{$scope}] row #{$row->id}: previous_hash doesn't match the prior row's hash — chain broken (a row may have been deleted, inserted, or reordered)");
                    $failures++;
                }

                $fields = collect($row->hashChainFields())
                    ->mapWithKeys(fn (string $field) => [$field => $row->getAttribute($field)])
                    ->all();
                $expectedHash = $modelClass::computeChainHash($expectedPrevious, $fields);

                if ($row->hash !== $expectedHash) {
                    $this->error("  [{$chain}:{$scope}] row #{$row->id}: stored hash doesn't match its own field values — row was altered after creation");
                    $failures++;
                }

                $lastHashByScope[$scope] = $row->hash;
            }
        });

        // A ChainHead not matching its scope's actual last row catches two
        // more cases a straight row scan can't: a trailing row deleted
        // (head is now stale, pointing past the end), or a whole scope
        // wiped out (head exists, no rows for it were ever seen above).
        ChainHead::where('chain', $chain)->orderBy('scope')->chunkById(500, function ($heads) use (&$failures, &$lastHashByScope, &$rowCountByScope, $chain) {
            foreach ($heads as $head) {
                $seenCount = $rowCountByScope[$head->scope] ?? 0;

                if ($seenCount === 0) {
                    $this->error("  [{$chain}:{$head->scope}] chain head exists but no rows were found for it — this scope's entire history may have been deleted");
                    $failures++;

                    continue;
                }

                if ($head->last_hash !== ($lastHashByScope[$head->scope] ?? null)) {
                    $this->error("  [{$chain}:{$head->scope}] chain head doesn't match the scope's last row — a trailing row may have been deleted");
                    $failures++;
                }
            }
        });

        return $failures;
    }
}
