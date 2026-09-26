<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response — the kind a pentest scanner
 * flags by default when they're missing. Deliberately conservative: this
 * app embeds arbitrary, admin-configured third-party video URLs (cockpit
 * stream_url) as iframes, so a strict Content-Security-Policy isn't set
 * here — doing that safely needs an inventory of every provider domain in
 * use first, and a wrong CSP would break live video, which is worse than
 * not having one yet. What's below never restricts anything this app
 * itself does.
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Clickjacking: nothing in this app needs to be framed by another
        // site.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Stops the browser guessing a response's content type from its
        // body (e.g. treating an uploaded image as HTML/JS).
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Full URLs (which can carry ticket/transaction codes in the path)
        // only get sent as a Referer header to this app's own origin;
        // cross-origin navigation still sends just the origin, not the
        // full path.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Browser features this app never uses — denying them costs
        // nothing and closes off a class of embedded-content abuse.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Only advertised over an actually-secure connection — the app
        // sits behind a reverse proxy (see bootstrap/app.php's
        // trustProxies), so $request->secure() correctly reflects the
        // original scheme via X-Forwarded-Proto rather than the proxy's
        // own plain-HTTP hop to PHP-FPM.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
