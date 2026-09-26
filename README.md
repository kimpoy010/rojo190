# Pool Sabong

A standalone pari-mutuel ("pool") betting app for sabong (cockfighting),
extracted from a larger multi-game betting platform and trimmed down to
just the pool-sabong game.

## What this is

Players bet on Meron / Wala / Draw for each fight. Bets settle against the
pool total (pari-mutuel), not against each other — there's no peer-to-peer
odds matching. A declarator runs the live fight lifecycle (open → close →
declare winner), and a superadmin manages events and game settings and can
manually credit/debit player wallets.

This is a **minimal runnable slice**: it keeps the pool-sabong betting
engine, wallet ledger, agent/commission hierarchy, and realtime updates
faithful to the reference platform's business rules, but drops the
webmaster back-office tooling, chat support, geoblocking, and every other
game the reference platform offered.

## Stack

- Laravel 13, SQLite
- Blade + Tailwind CSS v4, vanilla JS (no frontend framework)
- Laravel Reverb (WebSocket) + Laravel Echo for live pool/fight updates
- spatie/laravel-permission for roles (`player`, `declarator`, `superadmin`, `agent`, `teller`)
- endroid/qr-code (SVG, no GD/Imagick needed) for cash-in/cash-out QR codes

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
```

### Using MySQL instead of SQLite

The schema is plain Laravel migrations with no SQLite-specific syntax, so
MySQL (or MariaDB) works with no code changes — just point `.env` at it
instead of step 5-6 above:

```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=offline_bets
DB_USERNAME=offline_bets
DB_PASSWORD=secret
```

Create the database and user first (adjust credentials to taste):

```sql
CREATE DATABASE offline_bets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'offline_bets'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON offline_bets.* TO 'offline_bets'@'localhost';
FLUSH PRIVILEGES;
```

Then run `php artisan migrate --seed` as usual — no `database/database.sqlite`
file needed. This has been verified end-to-end against real MariaDB
(migrations, seeders, betting/settlement, agent commission, and the teller
QR cash-in/cash-out flow all behave identically to SQLite). The test suite
(`phpunit.xml`) still runs against an in-memory SQLite database regardless
of what your app's `.env` points at — that's just for test speed and is
unrelated to your production database choice.

## Running it

You need three processes for full realtime functionality:

```bash
php artisan serve          # app server
php artisan reverb:start   # websocket server
npm run dev                # asset watcher (optional in production)
```

### Accessing it from another device on your network

If you're opening the app from a phone or another computer rather than the
same machine (e.g. testing the teller QR flow with a real camera), `.env`'s
`REVERB_HOST` needs to be your machine's LAN IP instead of the default
`localhost`, since that's what the *other* device's browser will try to
connect to:

```
REVERB_HOST="192.168.x.x"
VITE_REVERB_HOST="${REVERB_HOST}"
```

Then serve on all interfaces, not just localhost:

```bash
php artisan serve --host=0.0.0.0
```

**`VITE_REVERB_*` values are compiled into the JS bundle at build time**,
not read live by the browser — after changing any of them you must rerun
`npm run build` (or restart `npm run dev`) for the change to actually take
effect, or the browser will keep trying to reach the old host.

If the browser console shows WebSocket errors with an *empty* app key in
the URL (`ws://.../app/?protocol=...` with nothing between `app/` and
`?`), `REVERB_APP_KEY`/`REVERB_APP_ID`/`REVERB_APP_SECRET` are blank in
your `.env` — `.env.example` ships with working local-dev defaults for
these already, so this only happens if they were cleared out somehow.
Live updates aren't required for the app to function either way — every
realtime view has a polling fallback (~4-5s) if the websocket can't
connect.

## Demo accounts

Seeded by `php artisan db:seed` (password: `password` for all):

| Role       | Email                    |
|------------|--------------------------|
| superadmin | superadmin@example.com   |
| declarator | declarator@example.com   |
| player     | player@example.com       |
| agent      | agent@example.com        |
| teller     | teller@example.com       |

The demo player is recruited under the demo agent out of the box, so a
fight settled with that player betting will visibly credit the agent's
commission balance.

## Agent / commission hierarchy

Agents recruit players (and other agents) via a referral link
(`/register?ref=<code>`) and earn commission on every meron/wala bet their
downline places — win or lose — based on a **differential-rate** model:

- Each agent has a per-game commission rate (`%`), set by a superadmin
  (`/superadmin/agents`), capped at the game's plasada rate.
- Walking up a bettor's agent chain, each agent earns only the *delta*
  between their own rate and the rate of the agent below them — so a 2%
  Level 1 agent with a 1.5% Level 2 sub-agent earns just the remaining
  0.5%, not another full 2%. This keeps the total commission paid out
  capped at the top agent's rate no matter how many levels deep the chain
  goes.
- Lowering an agent's rate automatically caps (cascades down to) every
  agent below them, so the model can never go insolvent.
- Commission accrues in a separate wallet balance an agent can transfer to
  their main balance at any time (`/agent`, "Transfer to main balance").
- Draw bets and voided (refunded) rounds never generate commission.

A registration link's meaning depends on who it belongs to: a link shared
by an **agent** recruits a new **player** under them; a link shared by the
**superadmin** creates a new top-level **agent**. Registering with no
referral link at all (or an unrecognized one) creates an ordinary,
unaffiliated player — registration is not gated behind a referral
requirement in this build.

## Teller cash-in / cash-out

Deposits and withdrawals go through a **teller** (cashier), separately from
bet payouts — a winning bet's payout is credited to the player's wallet
automatically the moment the fight is settled; it's only *getting that
money out* (or putting new money in) that requires a teller.

**Deposit ("cash in")**
1. Player enters an amount at `/play/cash` and generates a QR code. Nothing
   is credited yet.
2. The teller scans it (any phone camera opens the QR's URL straight into
   the approval screen at `/teller/scan/{code}`) or types the code shown
   under the QR into the teller dashboard manually.
3. The player hands over cash/e-wallet payment; the teller presses
   **Approve**, which credits the wallet.

**Withdrawal ("cash out")**
1. Player generates a withdrawal QR — the amount is always their full
   *available* balance, snapshotted at that moment.
2. That amount is immediately **reserved** (held out of what they can bet
   or request another withdrawal for) so it can't be double-spent while the
   request is pending, but their `main_balance` isn't touched yet.
3. The teller scans it and presses **Approve** — only now is the wallet
   actually debited, matching the spec's "cashier presses approve and the
   amount is deducted." The teller then hands over the payment.

Other rules: a player can only have one pending cash request at a time; QR
codes expire after 15 minutes if unscanned; either the player or the teller
can cancel/reject a pending request, which — for a withdrawal — releases
the reservation without ever touching `main_balance`.

## How a fight works

1. **Superadmin** creates an event (`/superadmin/events/create`).
2. **Declarator** starts the event, which creates Fight #1
   (`/declarator/events/{event}`).
3. Declarator opens betting → **players** place Meron/Wala/Draw bets
   (`/play`) → declarator closes betting → declarator declares a winner.
4. Bets settle automatically: winners are paid pari-mutuel (pool minus
   house rake, split among winners), draw bettors are paid a fixed
   multiplier funded by the house, and if the pool is so lopsided that
   winners would be paid less than their stake, the round is voided and
   everyone is refunded instead.
5. The next fight is created automatically; repeat.

## Tests

```bash
php artisan test
```

Covers the payout math (`PoolPayoutCalculator`), the settlement rules in
`BettingService` (pari-mutuel payout, draw multiplier, void-round refund,
idempotent settlement), the draw-pool betting cap, the cosmetic
display-multiplier scaling on the player page, the differential-rate
commission chain (`CommissionService`) including its integration with
settlement, and the teller cash-in/cash-out flow (`CashTransactionService`
— deposit only credits on approval, withdrawal reserves-then-debits,
expiry, cancellation, the one-pending-request rule).

## Production deployment

Every broadcast in this app (`WalletBalanceUpdated`, `BetPoolUpdated`, ...) is
sent inline (`ShouldBroadcastNow`), not queued — a payout or a live pool
update needs to reach a player fast, and the `database` queue driver's
polling could otherwise add up to a full second of latency before a worker
even picked the job up. This means **no queue worker is currently required**
for the app to function correctly; `deploy/supervisor/laravel-worker.conf`
is kept in the repo in case a future feature genuinely needs a queue
(non-latency-sensitive background work), but nothing in the app dispatches
to one today.

### Stress testing

See `deploy/loadtest/README.md` for a step-by-step guide to load-testing the
betting flow with k6 from a separate VPS, including the
`loadtest:seed-players`/`loadtest:cleanup` Artisan commands that create
pre-authenticated test bettor accounts without going through the throttled
login route.
