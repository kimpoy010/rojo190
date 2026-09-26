import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const _reverbTLS = (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: _reverbTLS,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

// Pages listen for their live updates purely via broadcast — no background
// polling — but a page that's been open for a while can miss updates during
// a dropped connection (a phone going through a tunnel, wifi hiccup, tab
// backgrounded and throttled). This fires `callback` once every time the
// socket reaches 'connected', including the very first time, so a caller
// can do a single resync fetch instead of guessing whether anything was
// missed. Returns the underlying unsubscribe function for callers that need
// to re-register it (e.g. after replacing the DOM it updates).
//
// Defined before the echo:ready dispatch below, not after — dispatchEvent
// is synchronous, so a listener reacting to echo:ready that calls this
// would otherwise run before this assignment ever happened.
window.onEchoReconnect = function (callback) {
    // Bound straight to the underlying Pusher connection's own 'connected'
    // event rather than Echo's onConnectionChange() — that helper also
    // binds 'state_change', which fires a second time for the same
    // transition into 'connected', double-firing the callback.
    const connection = window.Echo.connector.pusher.connection;
    connection.bind('connected', callback);

    return () => connection.unbind('connected', callback);
};

// Signal inline scripts (which run before this module) that Echo is ready.
window.dispatchEvent(new Event('echo:ready'));
