<?php

namespace App\Http\Middleware;

use App\Models\RfidTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an ESP32 reader board against the API: it sends the
 * terminal's token as a Bearer credential (the same token that's also the
 * unguessable segment of the kiosk's public URL — configured once in the
 * board's firmware, shared by every reader at that station). Resolves the
 * terminal and makes it available to the controller as
 * $request->rfidTerminal — no user/session involved, this is
 * machine-to-machine.
 */
class AuthenticateRfidTerminal
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $terminal = $token
            ? RfidTerminal::where('token', $token)->where('is_active', true)->first()
            : null;

        if (! $terminal) {
            abort(401, 'Invalid or inactive terminal token.');
        }

        $request->attributes->set('rfidTerminal', $terminal);

        return $next($request);
    }
}
