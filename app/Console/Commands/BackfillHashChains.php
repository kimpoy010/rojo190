<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\CashTransaction;
use App\Models\ChainHead;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One-time (safe to re-run — only ever touches rows with hash still NULL)
 * backfill that hash-chains every financial row that predates this feature,
 * exactly as if the chain had existed from that scope's very first row.
 *
 * Important limit: this can't prove pre-backfill data wasn't already
 * altered before it ran — only ledger:verify running against rows created
 * *after* this point can make that guarantee. What it does buy: every
 * existing row joins the same tamper-evident chain going forward, instead
 * of being permanently outside it.
 */
class BackfillHashChains extends Command
{
    protected $signature = 'ledger:backfill {--chain= : Only backfill one table (wallet_transactions, cash_transactions, bets, or audit_logs)}';

    protected $description = 'Retroactively hash-chain every existing financial row that predates hashing';

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

        foreach ($chains as $chain => $modelClass) {
            $this->backfillChain($chain, $modelClass);
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function backfillChain(string $chain, string $modelClass): void
    {
        $this->line("Backfilling {$chain}...");

        $count = 0;
        $lastHashByScope = [];

        $modelClass::whereNull('hash')->orderBy('id')->chunkById(500, function ($rows) use (&$count, &$lastHashByScope, $chain, $modelClass) {
            DB::transaction(function () use ($rows, &$count, &$lastHashByScope, $chain, $modelClass) {
                foreach ($rows as $row) {
                    $scope = $row->hashChainScope();

                    // First time this scope is seen in this run — resume
                    // from wherever its chain actually left off (rows
                    // created normally through the app since this feature
                    // shipped, or a prior partial backfill), rather than
                    // assuming it starts from nothing.
                    if (! array_key_exists($scope, $lastHashByScope)) {
                        $head = ChainHead::where('chain', $chain)->where('scope', $scope)->lockForUpdate()->first();
                        $lastHashByScope[$scope] = $head?->last_hash;
                    }

                    $fields = collect($row->hashChainFields())
                        ->mapWithKeys(fn (string $field) => [$field => $row->getAttribute($field)])
                        ->all();
                    $hash = $modelClass::computeChainHash($lastHashByScope[$scope], $fields);

                    $row->forceFill(['hash' => $hash, 'previous_hash' => $lastHashByScope[$scope]])->saveQuietly();

                    ChainHead::updateOrCreate(
                        ['chain' => $chain, 'scope' => $scope],
                        ['last_hash' => $hash],
                    );

                    $lastHashByScope[$scope] = $hash;
                    $count++;
                }
            });
        });

        $this->info("  {$count} row(s) backfilled.");
    }
}
