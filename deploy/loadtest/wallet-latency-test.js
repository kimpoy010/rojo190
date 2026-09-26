// Measures the thing the <50ms target is actually about: the time from a
// player placing a bet to that player's own wallet balance update actually
// arriving over the WebSocket connection (the WalletBalanceUpdated
// broadcast — see app/Events/WalletBalanceUpdated.php, sent inline
// specifically so this number stays low). bet-load-test.js only measures
// the HTTP request/response time for placing the bet, which is a different
// (and much less interesting) number — this script is the one that answers
// "will players actually see their balance update fast enough."
//
// Each VU: connects to Reverb, subscribes to its own private wallet.{id}
// channel the same way the real app's Echo client does (POST
// /broadcasting/auth for a signed channel token, then pusher:subscribe over
// the socket), places one bet, and times how long the resulting
// WalletBalanceUpdated event takes to arrive back over that same socket.
//
// Usage (see deploy/loadtest/README.md):
//   k6 run -e BASE_URL=https://your-app.example.com \
//           -e FIGHT_ID=123 \
//           -e COOKIE_NAME=sabong-pool-betting-session \
//           -e WS_HOST=your-app.example.com \
//           -e WS_PORT=443 \
//           -e WS_SCHEME=wss \
//           -e REVERB_APP_KEY=your-reverb-app-key \
//           deploy/loadtest/wallet-latency-test.js
//
// Run this at modest concurrency (tens of VUs, not thousands) — it's meant
// to answer "how fast," not to also double as the throughput test. For
// realistic numbers, run it *while* bet-load-test.js is hammering the app
// in a separate k6 process, so the latency you measure reflects the app
// under real load rather than an idle server.

import ws from 'k6/ws';
import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import papaparse from 'https://jslib.k6.io/papaparse/5.1.1/index.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const FIGHT_ID = __ENV.FIGHT_ID;
const COOKIE_NAME = __ENV.COOKIE_NAME;
const WS_HOST = __ENV.WS_HOST;
const WS_PORT = __ENV.WS_PORT || '443';
const WS_SCHEME = __ENV.WS_SCHEME || 'wss'; // production is almost always TLS-terminated
const REVERB_APP_KEY = __ENV.REVERB_APP_KEY;
const BROADCAST_TIMEOUT_MS = 10000; // give up waiting for the event after this long

for (const [name, value] of Object.entries({ FIGHT_ID, COOKIE_NAME, WS_HOST, REVERB_APP_KEY })) {
    if (!value) {
        throw new Error(`Set -e ${name}=... — see this file's header comment or README.md.`);
    }
}

const sessions = new SharedArray('sessions', function () {
    return papaparse.parse(open('./loadtest-sessions.csv'), { header: true }).data
        .filter((row) => row.username && row.user_id);
});

// One dedicated wallet-broadcast-latency metric (in ms), plus separate
// failure counters so a broken auth/subscribe flow shows up distinctly
// from "the broadcast just never arrived."
const walletBroadcastLatency = new Trend('wallet_broadcast_latency', true);
const authFailureRate = new Rate('broadcast_auth_failures');
const subscribeFailureRate = new Rate('subscribe_failures');
const broadcastMissRate = new Rate('broadcast_misses');

export const options = {
    scenarios: {
        latency_probe: {
            executor: 'constant-vus',
            vus: Number(__ENV.CONCURRENCY) || 20,
            duration: __ENV.DURATION || '2m',
        },
    },
    thresholds: {
        // The actual requirement: p95 of bet-to-balance-update under 50ms.
        wallet_broadcast_latency: ['p(95)<50'],
        broadcast_misses: ['rate<0.01'],
        subscribe_failures: ['rate<0.01'],
    },
};

export default function () {
    const row = sessions[__VU % sessions.length];
    const cookies = { [COOKIE_NAME]: row.session_cookie };
    const channelName = `private-wallet.${row.user_id}`;
    const wsUrl = `${WS_SCHEME}://${WS_HOST}:${WS_PORT}/app/${REVERB_APP_KEY}?protocol=7&client=js&version=8.4.0&flash=false`;

    let betPlacedAt = null;
    let sawSubscriptionSucceed = false;
    let sawBroadcast = false;

    const res = ws.connect(wsUrl, {}, function (socket) {
        socket.on('message', (raw) => {
            const msg = JSON.parse(raw);

            if (msg.event === 'pusher:connection_established') {
                const socketId = JSON.parse(msg.data).socket_id;

                // Same channel-auth handshake Laravel Echo performs in a real
                // browser — a signed token for this specific socket+channel
                // pair, proving (server-side, via routes/channels.php) that
                // this session is actually allowed to subscribe to its own
                // wallet.{id} channel.
                const authRes = http.post(
                    `${BASE_URL}/broadcasting/auth`,
                    `channel_name=${encodeURIComponent(channelName)}&socket_id=${encodeURIComponent(socketId)}`,
                    {
                        cookies,
                        headers: {
                            'X-CSRF-TOKEN': row.csrf_token,
                            'Content-Type': 'application/x-www-form-urlencoded',
                            Accept: 'application/json',
                        },
                    }
                );

                if (authRes.status !== 200) {
                    authFailureRate.add(1);
                    socket.close();
                    return;
                }
                authFailureRate.add(0);

                socket.send(JSON.stringify({
                    event: 'pusher:subscribe',
                    data: { channel: channelName, auth: authRes.json('auth') },
                }));
                return;
            }

            if (msg.event === 'pusher_internal:subscription_succeeded' && msg.channel === channelName) {
                sawSubscriptionSucceed = true;
                subscribeFailureRate.add(0);

                // Only place the bet once we're actually listening — placing
                // it any earlier risks the broadcast firing before the
                // subscription exists, which would look like a "miss" that's
                // really just a race in the test itself, not the app.
                betPlacedAt = Date.now();
                const betRes = http.post(
                    `${BASE_URL}/play/fight/${FIGHT_ID}/bet`,
                    JSON.stringify({
                        side: ['meron', 'wala'][Math.floor(Math.random() * 2)],
                        amount: Math.floor(Math.random() * 450) + 50,
                    }),
                    {
                        cookies,
                        headers: {
                            'X-CSRF-TOKEN': row.csrf_token,
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                        },
                        tags: { name: 'placeBet' },
                    }
                );
                check(betRes, { 'bet accepted': (r) => r.status === 200 });
                return;
            }

            if (msg.event === 'WalletBalanceUpdated' && msg.channel === channelName && betPlacedAt) {
                walletBroadcastLatency.add(Date.now() - betPlacedAt);
                sawBroadcast = true;
                socket.close();
            }
        });

        socket.setTimeout(() => socket.close(), BROADCAST_TIMEOUT_MS);

        socket.on('close', () => {
            if (!sawSubscriptionSucceed) {
                subscribeFailureRate.add(1);
            } else if (!sawBroadcast) {
                broadcastMissRate.add(1);
            } else {
                broadcastMissRate.add(0);
            }
        });
    });

    check(res, { 'websocket connected': (r) => r && r.status === 101 });
}
