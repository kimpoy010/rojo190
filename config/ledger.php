<?php

return [

    /*
    |--------------------------------------------------------------------
    | Ledger hash-chain key
    |--------------------------------------------------------------------
    |
    | The secret used to HMAC every financial ledger row's hash (bets,
    | cash_transactions, wallet_transactions — see HasHashChain). Keeping
    | this separate from APP_KEY means rotating one doesn't force
    | re-signing the other's data, but it falls back to APP_KEY so a
    | fresh install works without extra setup.
    |
    | Never commit a real value for LEDGER_HASH_KEY — it must only live in
    | .env. Losing or rotating it invalidates every previously computed
    | hash (`ledger:verify` will report the whole history as tampered),
    | so treat it like any other production secret.
    |
    */

    'hash_key' => env('LEDGER_HASH_KEY', env('APP_KEY')),

];
