<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecureHeadersTest extends TestCase
{
    public function test_baseline_security_headers_are_present_on_every_response(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_hsts_is_only_sent_over_an_actually_secure_request(): void
    {
        $plain = $this->get(route('login'));
        $plain->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->get('https://'.parse_url(route('login'), PHP_URL_HOST).'/login');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
