<?php

namespace App\Support;

class StreamUrl
{
    /**
     * A cockpit's stream_url is an opaque, cross-origin embed URL — the app
     * has no way to reach into that iframe and control its player via JS.
     * The best it can do is ask nicely via the query-param conventions
     * common embed providers (YouTube, Vimeo, Twitch, ...) already honor:
     * muted + autoplaying + chromeless. Used anywhere a stream plays
     * silently in the background with nothing to interact with — a small
     * floating PiP tile, or an event-list tile preview — without affecting
     * the main video a player watches once they're actually on the fight
     * page (that one keeps its own controls and stays untouched by this).
     */
    public static function autoplayMuted(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }

        // Providers spell "muted" differently, so this asks with every
        // common alias rather than betting on one — mute=1 (YouTube),
        // muted=1/muted=true and sound=0 (various custom HLS players).
        return $url.(str_contains($url, '?') ? '&' : '?').'autoplay=1&mute=1&muted=1&muted=true&sound=0&controls=0';
    }
}
