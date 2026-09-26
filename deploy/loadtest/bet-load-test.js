// k6 load test for the pool-betting flow — hits GET /play/fight/{id}/status
// (the polling fallback every player page does) and POST /play/fight/{id}/bet
// (placing a bet), using pre-authenticated sessions from
// `php artisan loadtest:seed-players` instead of logging in live (see that
// command's own docblock for why: POST /login is throttled 10/min per IP,
// and every simulated bettor hitting it from one k6 box's single IP would
// trip that limit immediately).
//
// Usage (see deploy/loadtest/README.md for the full walkthrough):
//   k6 run -e BASE_URL=https://your-app.example.com \
//           -e FIGHT_ID=123 \
//           -e COOKIE_NAME=sabong-pool-betting-session \
//           deploy/loadtest/bet-load-test.js
//
// deploy/loadtest/loadtest-sessions.csv (copied over from the app server's
// `storage/app/loadtest-sessions.csv`) must sit next to this file.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import papaparse from 'https://jslib.k6.io/papaparse/5.1.1/index.js';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';
import { htmlReport } from 'https://raw.githubusercontent.com/benc-uk/k6-reporter/main/dist/bundle.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const FIGHT_ID = __ENV.FIGHT_ID;
const COOKIE_NAME = __ENV.COOKIE_NAME;

if (!FIGHT_ID) {
    throw new Error('Set -e FIGHT_ID=<an open fight\'s id> — see README.md step 5.');
}
if (!COOKIE_NAME) {
    throw new Error('Set -e COOKIE_NAME=<your app\'s session cookie name> — see README.md step 5.');
}

// SharedArray loads and parses the CSV once, then shares it read-only across
// every VU (not re-read/re-parsed per VU) — this file can be tens of
// thousands of rows without blowing up memory per VU.
const sessions = new SharedArray('sessions', function () {
    return papaparse.parse(open('./loadtest-sessions.csv'), { header: true }).data
        .filter((row) => row.username);
});

// Each VU sticks to the same one player account for its entire run — one
// simulated bettor, not a pool of VUs fighting over shared rows. Run with no
// more VUs than there are rows in the CSV (k6 will just reuse rows, which
// means two VUs placing bets as the "same player" — harmless, just not
// representative of distinct bettors).
export function setup() {
    if (sessions.length === 0) {
        throw new Error('loadtest-sessions.csv has no rows — did loadtest:seed-players run?');
    }
}

export const options = {
    scenarios: {
        bettors: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 500 },   // warm up
                { duration: '1m', target: 2000 },   // ramp toward target load
                { duration: '3m', target: 2000 },   // hold — this is the number that matters
                { duration: '30s', target: 0 },      // ramp down
            ],
            gracefulRampDown: '30s',
        },
    },
    thresholds: {
        // Tune these to what "pass" means for you before a real run — these
        // are reasonable starting points, not requirements from anywhere.
        http_req_failed: ['rate<0.02'],       // fewer than 2% hard failures
        'http_req_duration{name:placeBet}': ['p(95)<2000'],
    },
};

export default function () {
    const row = sessions[__VU % sessions.length];
    const cookies = { [COOKIE_NAME]: row.session_cookie };
    const jsonHeaders = {
        'X-CSRF-TOKEN': row.csrf_token,
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    // Poll the fight's live status, same as a player's browser does every
    // few seconds while a fight is open.
    const statusRes = http.get(`${BASE_URL}/play/fight/${FIGHT_ID}/status`, {
        cookies,
        headers: { Accept: 'application/json' },
        tags: { name: 'status' },
    });
    check(statusRes, { 'status 200': (r) => r.status === 200 });

    // Place a bet — random side, random stake within a modest range so the
    // payout math has something realistic to chew on.
    const side = ['meron', 'wala'][Math.floor(Math.random() * 2)];
    const amount = Math.floor(Math.random() * 450) + 50; // 50–500

    const betRes = http.post(
        `${BASE_URL}/play/fight/${FIGHT_ID}/bet`,
        JSON.stringify({ side, amount }),
        { cookies, headers: jsonHeaders, tags: { name: 'placeBet' } }
    );

    check(betRes, {
        'bet accepted or expected rejection': (r) => r.status === 200 || r.status === 422,
    });

    sleep(1 + Math.random() * 4); // 1–5s between actions, not a hammer
}

// Writes a self-contained HTML report and a machine-readable JSON summary
// when the run finishes, instead of leaving the numbers to scroll off the
// terminal. Run `php artisan loadtest:report` on the app server afterward
// for the other half of the picture — this only reports on the HTTP
// requests themselves, not whether the bets they represent actually landed
// correctly in the database.
export function handleSummary(data) {
    return {
        stdout: textSummary(data, { indent: ' ', enableColors: true }),
        'summary.html': htmlReport(data),
        'summary.json': JSON.stringify(data, null, 2),
    };
}
