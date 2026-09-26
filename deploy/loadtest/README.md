# Stress testing the betting flow with k6

Two machines are involved:

- **App server** — a VPS running this app exactly as production would (same
  specs, same `.env` tuning). This is the box being tested.
- **Load-generator VPS** — a *separate* box that runs k6 and fires requests
  at the app server. Never run k6 on the app server itself — it would be
  competing with the app for the same CPU/RAM you're trying to measure.

Everything below assumes you already have both boxes provisioned and can
SSH into each.

## 1. Confirm the app server is tuned, not just deployed

A stress test on an untuned server only tells you the server is untuned —
you already know that. Before running anything, confirm on the **app
server**:

- **MySQL `max_connections`** is raised from the 151 default to something
  that comfortably covers `pm.max_children` (see next point) plus the queue
  worker's own connections, with headroom. Check with:
  ```sql
  SHOW VARIABLES LIKE 'max_connections';
  ```
- **PHP-FPM `pm.max_children`** (in your pool's `.conf`, usually
  `/etc/php/*/fpm/pool.d/www.conf`) is sized for your RAM budget, not left
  at a small default like `5`. Each PHP-FPM child holds its own memory —
  size this against actual free RAM on the box, not just CPU count.
- **No queue worker needed** — every broadcast in this app (including the
  payout/balance update) is sent inline, not queued, specifically to avoid
  the `database` queue driver's polling delay. `deploy/supervisor/laravel-worker.conf`
  exists only for a possible future non-latency-sensitive feature; nothing
  in the app dispatches to it today, so there's nothing to check here.
- **Reverb is running** (`php artisan reverb:start`, or under its own
  supervisor program) if you want to test realtime updates under load, not
  just the HTTP betting flow.
- **`APP_ENV=production`** and `APP_DEBUG=false` in `.env` — a debug-mode
  error page on every failed request under load will skew your numbers and
  leak stack traces.

If you haven't done this tuning pass yet on this new VPS, do it first —
otherwise the test just tells you what you already knew (5 PHP-FPM workers
can't serve 2,000 concurrent requests).

## 2. Seed load-test player accounts (on the app server)

This app's login route is throttled 10/min per IP specifically to block
credential stuffing — if every simulated bettor logged in live from the
load-generator's one IP, you'd hit that throttle almost immediately and the
test would just measure your own anti-brute-force protection. Instead, a
dedicated Artisan command creates player accounts *and* a pre-authenticated
session for each one directly, bypassing `POST /login` entirely:

```bash
cd /var/www/offline-bets   # or wherever this app lives on the app server
php artisan loadtest:seed-players 2000 --balance=1000000
```

- `2000` here is how many simulated bettors to create — match it to
  whatever peak concurrency you're testing (your target is 5,000–10,000, so
  test in that range once smaller runs look healthy).
- This writes `storage/app/loadtest-sessions.csv` with one row per account:
  `username,session_cookie,csrf_token`.
- Accounts are named `loadtest_player_1`, `loadtest_player_2`, ... with
  `@loadtest.invalid` emails — impossible to confuse with real players, and
  the app is asked to confirm before touching the production database (skip
  the prompt for automation with `--force`).
- Running it again with a higher count is safe — existing accounts are
  reused (`firstOrCreate`), only new ones are added, and every account's
  balance is reset to `--balance` each time.

## 3. Copy the sessions CSV to the load-generator VPS

```bash
scp appserver:/var/www/offline-bets/storage/app/loadtest-sessions.csv \
    ./deploy/loadtest/loadtest-sessions.csv
```

(Run this from wherever you have SSH access to both boxes — your own laptop
is fine — or `scp` directly between the two VPSes.) The k6 script expects
this file to sit right next to it, at `deploy/loadtest/loadtest-sessions.csv`.

## 4. Install k6 on the load-generator VPS

```bash
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

(If that keyserver is unreachable, k6's own install docs at
https://k6.io/docs/get-started/installation/ have current alternatives —
this changes occasionally and isn't something worth hardcoding here.)

Copy the whole `deploy/loadtest/` folder (the script + the CSV you copied in
step 3) onto this VPS, e.g. via `git clone` of this repo or a plain `scp -r`.

## 5. Open a fight to bet against (on the app server, via the normal app UI)

The load test needs a real, currently **open** fight to place bets on:

1. As superadmin, create an event: `/superadmin/events/create`.
2. As declarator, start it and open betting: `/declarator/events/{event}`.
3. Note that fight's ID — easiest way is the URL when you view it as a
   player, `/play/fight/{id}`, or query it directly:
   ```bash
   php artisan tinker --execute="echo App\Models\Fight::where('status', 'open')->latest()->first()->id;"
   ```

Also find your app's session cookie name — it's whatever `SESSION_COOKIE`
is set to in the app server's `.env`, or if that's unset, it's
`Str::slug(APP_NAME).'-session'` (e.g. `APP_NAME="Sabong Pool Betting"` →
`sabong-pool-betting-session`). Check with:
```bash
php artisan tinker --execute="echo config('session.cookie');"
```

## 6. Run it (on the load-generator VPS)

```bash
cd deploy/loadtest
k6 run -e BASE_URL=https://your-app.example.com \
        -e FIGHT_ID=123 \
        -e COOKIE_NAME=sabong-pool-betting-session \
        bet-load-test.js
```

The script ramps 0 → 500 → 2,000 virtual users by default (each one holding
one pre-authenticated player account, polling fight status and placing bets
on a loop) and holds at 2,000 for 3 minutes before ramping back down. Open
`deploy/loadtest/bet-load-test.js` and adjust the `stages` array to match
whatever concurrency you're actually trying to validate — there's nothing
special about 2,000, it's just a starting point below your 5,000–10,000
target so you can find where things start to strain before jumping straight
to the top.

When it finishes, the script writes two files into the directory you ran it
from (in addition to printing a summary to the terminal):
- **`summary.html`** — a self-contained, shareable report you can open in a
  browser (or attach to a message) instead of screenshotting a terminal.
- **`summary.json`** — the same data in machine-readable form, if you want to
  script comparisons between runs later.

**While it runs**, watch the app server in a second terminal:
```bash
# PHP-FPM: are workers maxed out / requests queueing?
sudo systemctl status php8.4-fpm    # or your version
watch -n1 'echo "SHOW STATUS LIKE \"Threads_connected\";" | mysql -u root -p'

# MySQL: connection count against max_connections from step 1
mysql -u root -p -e "SHOW STATUS LIKE 'Threads_connected';"

# General resource pressure
htop
```

## 7. Read the results

k6 prints a summary when it finishes. The numbers that actually answer "will
it pass":

- **`http_req_failed`** — the percentage of requests that errored outright
  (timeouts, 5xx, connection refused). This is the headline number; the
  script's built-in threshold fails the run if it's ≥2%, but decide for
  yourself what's acceptable for a betting app under real conditions.
- **`http_req_duration` for the `placeBet` tag** — how long a bet placement
  actually took. A slow-but-successful bet is a much better failure mode
  than a dropped one, but a p95 in the several-second range during a live
  fight's closing seconds is still a real problem for players trying to get
  a bet in before betting closes.

If `http_req_failed` climbs sharply at a specific VU count, that's your
practical ceiling on this hardware — compare it against your 5,000–10,000
target and go back to step 1's tuning if it's short.

`http_req_duration{name:placeBet}` here is the HTTP request/response time
for placing a bet — a different thing from how fast the resulting
`WalletBalanceUpdated` broadcast reaches a player over the WebSocket
connection, which is the actual <50ms target. Step 7 measures that
specifically.

## 7. Measure the actual <50ms target: bet-to-balance-update latency

`bet-load-test.js` doesn't touch WebSockets at all, so it can't answer the
one question the stress test is actually supposed to answer here. A
separate script, `wallet-latency-test.js`, does: each of its VUs connects to
Reverb, subscribes to its own private `wallet.{id}` channel exactly the way
the real app's Echo client does (a signed `/broadcasting/auth` token, then
`pusher:subscribe`), places one bet, and times how long the
`WalletBalanceUpdated` event takes to arrive back over that socket.

You'll need a few more values from the app server's `.env` alongside
`COOKIE_NAME` from step 5:
```bash
php artisan tinker --execute="echo config('reverb.apps.apps.0.key');"   # REVERB_APP_KEY
```
`WS_HOST`/`WS_PORT` are wherever Reverb is actually reachable from the
outside — if it's behind the same reverse proxy as the app (typical), that's
usually your normal domain on port 443 with `WS_SCHEME=wss`; if Reverb is
exposed directly, use `REVERB_PORT` from `.env` instead.

Run it **while `bet-load-test.js` is also running** (as a separate k6
process, or a second terminal) — latency measured against an idle server
tells you nothing about whether it holds up at your actual target
concurrency:
```bash
cd deploy/loadtest
k6 run -e BASE_URL=https://your-app.example.com \
        -e FIGHT_ID=123 \
        -e COOKIE_NAME=sabong-pool-betting-session \
        -e WS_HOST=your-app.example.com \
        -e WS_PORT=443 \
        -e WS_SCHEME=wss \
        -e REVERB_APP_KEY=your-reverb-app-key \
        wallet-latency-test.js
```

It defaults to 20 constant VUs for 2 minutes (`-e CONCURRENCY=` /
`-e DURATION=` to change) — this is deliberately modest; it's measuring
*how fast*, not adding to the throughput load itself. It also writes its own
`summary.html`/`summary.json` (will overwrite `bet-load-test.js`'s if run
from the same directory — rename or run from separate directories if you
want to keep both).

The metric that matters: **`wallet_broadcast_latency`**, its `p(95)` against
the built-in `<50ms` threshold. Two other metrics tell you *why* if it
fails, rather than just that it did:
- **`subscribe_failures`** — the WebSocket auth/subscribe handshake itself
  failed (a `/broadcasting/auth` problem, not a latency problem).
- **`broadcast_misses`** — successfully subscribed, bet placed, but no
  `WalletBalanceUpdated` ever arrived within 10s. Under real load this
  usually means Reverb itself is the bottleneck (CPU-bound on the app
  server, competing with PHP-FPM and MySQL for the same 2 vCPUs) rather
  than anything queue-related, since the broadcast is sent inline now.

## 8. Generate the data-integrity report (on the app server)

This checks the database directly: every bet `loadtest_player_*` placed,
and — the part k6 can't see — whether each account's wallet balance
actually matches what the bet ledger says it should be. A request coming
back 200 doesn't guarantee the debit landed; under real concurrency, a lost
or duplicated write is exactly the kind of bug a stress test exists to
surface, and it's invisible to k6's own metrics either way.

```bash
php artisan loadtest:report --starting-balance=1000000
```

`--starting-balance` must match whatever `--balance` you passed to
`loadtest:seed-players` in step 2 (both default to `1000000`) — it's what
each account's expected balance is reconciled against. Add `--since="2026-09-19
14:00:00"` if you ran more than one test without cleaning up in between, so
only bets from this run are counted.

It prints a bet-count/staked/paid-out summary and a wallet reconciliation
table to the terminal, exits non-zero if any account's balance doesn't
match, and writes the full detail to `storage/app/loadtest-report.json`
(override with `--output=`). Any mismatch listed is worth investigating
before you trust the run's other numbers — it means money moved
incorrectly somewhere under load, which matters far more than a slow
response time.

## 9. Clean up afterward

On the **app server**, remove every account the test created and everything
that cascades from it (bets, wallet, wallet transactions):

```bash
php artisan loadtest:cleanup
rm -f storage/app/loadtest-sessions.csv storage/app/loadtest-report.json
```

On the **load-generator VPS**, delete `deploy/loadtest/loadtest-sessions.csv`
too — it's a live batch of valid session cookies for real (if fake) accounts
and shouldn't be left lying around after the run.
