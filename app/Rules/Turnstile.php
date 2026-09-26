<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Cloudflare Turnstile response token server-side against
 * Cloudflare's siteverify endpoint — the widget itself only proves the
 * browser solved the challenge, a request could still forge the token
 * client-side without this check.
 *
 * A no-op (always passes) when TURNSTILE_SECRET_KEY isn't configured, so
 * local/test environments — which never render the widget either, see
 * the login/register views — don't need real Cloudflare credentials.
 *
 * Callers must pair this with an explicit 'required' rule (only when a
 * secret key is configured — see Turnstile::rules()) since a plain
 * ValidationRule is skipped entirely by Laravel when its field is absent
 * from the request, which would otherwise let a request through simply
 * by omitting cf-turnstile-response.
 */
class Turnstile implements ValidationRule
{
    /**
     * The full rule array for the 'cf-turnstile-response' field — only
     * 'required' when a secret key is actually configured, so local/test
     * requests that never render the widget aren't forced to send one.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return config('services.turnstile.secret_key')
            ? ['required', new self]
            : [new self];
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $secret = config('services.turnstile.secret_key');

        if (! $secret) {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail(__('Please complete the human verification challenge.'));

            return;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $value,
                'remoteip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            // Cloudflare unreachable — fail closed (block the request)
            // rather than silently letting every submission through.
            Log::warning('Turnstile verification request failed.', ['error' => $e->getMessage()]);
            $fail(__('Human verification is temporarily unavailable. Please try again.'));

            return;
        }

        if (! $response->json('success')) {
            $fail(__('Human verification failed. Please try again.'));
        }
    }
}
