<?php

namespace App\Models\Concerns;

use App\Models\ChainHead;
use Illuminate\Database\QueryException;

/**
 * Chains every row of a table into a tamper-evident sequence, independently
 * per "scope" (e.g. one chain per wallet, so unrelated wallets' writes
 * never block each other): each row's hash covers its own immutable fields
 * plus the previous row's hash within that same scope, so altering,
 * deleting, or inserting a row anywhere in a chain's history breaks every
 * hash after it — detectable later without trusting anything else in the
 * database. Hashes are HMAC-signed with config('ledger.hash_key'), not a
 * plain hash, so a forged replacement chain requires that secret, not just
 * database write access. See app/Console/Commands/VerifyHashChains.php.
 *
 * Implementing models define:
 *  - hashChainFields(): the immutable columns to protect. Never include a
 *    column mutated after insert (e.g. a bet's later settlement status) —
 *    creating() fires before those exist to be tampered with anyway, and
 *    this is a chain of *creation facts*, not current state.
 *  - hashChainScope(): the independent-chain key (e.g. the wallet id).
 *
 * Relies on every caller already wrapping the write in DB::transaction()
 * (true of every financial write in this app) — the chain-head lock below
 * rides along inside that same transaction rather than opening its own.
 */
trait HasHashChain
{
    protected static function bootHasHashChain(): void
    {
        static::creating(function ($model) {
            $model->applyHashChain();
        });
    }

    protected function applyHashChain(): void
    {
        $head = static::lockChainHead($this->getTable(), $this->hashChainScope());

        $fields = collect($this->hashChainFields())
            ->mapWithKeys(fn (string $field) => [$field => $this->getAttribute($field)])
            ->all();

        $hash = static::computeChainHash($head->last_hash, $fields);

        $this->setAttribute('previous_hash', $head->last_hash);
        $this->setAttribute('hash', $hash);

        $head->update(['last_hash' => $hash]);
    }

    /**
     * Locks (creating first if this scope has never chained before) the
     * tail row for a given chain+scope.
     */
    protected static function lockChainHead(string $chain, string $scope): ChainHead
    {
        $head = ChainHead::where('chain', $chain)->where('scope', $scope)->lockForUpdate()->first();

        if ($head) {
            return $head;
        }

        // First row ever for this scope — two concurrent transactions can
        // both miss the SELECT above and race to INSERT the head row; let
        // the loser of that race fall back to a locked re-select instead
        // of blowing up on the unique(chain, scope) constraint.
        try {
            return ChainHead::create(['chain' => $chain, 'scope' => $scope, 'last_hash' => null]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return ChainHead::where('chain', $chain)->where('scope', $scope)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * The exact formula a verifier must reproduce to confirm a row wasn't
     * altered — fields sorted so their order in the caller's array never
     * changes the hash.
     *
     * HMAC-signed (config('ledger.hash_key')) rather than a plain hash: a
     * plain sha256 is only "public math" — anyone with database write
     * access could forge a replacement chain that still verifies, since
     * nothing about the formula is secret. Keying it means a valid chain
     * can only be produced by something that also holds the app's secret,
     * so raw DB tampering (without also compromising the app itself) is
     * detectable, not just accidental corruption or reordering.
     */
    public static function computeChainHash(?string $previousHash, array $fields): string
    {
        ksort($fields);

        return hash_hmac('sha256', ($previousHash ?? '').'|'.json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), static::hashChainKey());
    }

    /**
     * Resolved once per call rather than cached statically so a key
     * rotated mid-request (e.g. in a test) takes effect immediately.
     */
    protected static function hashChainKey(): string
    {
        $key = config('ledger.hash_key');

        if (blank($key)) {
            throw new \RuntimeException('ledger.hash_key is not configured — set LEDGER_HASH_KEY (or APP_KEY) before writing to a hash-chained table.');
        }

        return $key;
    }

    /**
     * @return array<int, string> column names whose values are hashed
     */
    abstract public function hashChainFields(): array;

    /**
     * @return string the independent-chain key this row belongs to
     */
    abstract public function hashChainScope(): string;
}
