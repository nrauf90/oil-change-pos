<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AddSecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_baseline_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_a_plaintext_response_does_not_announce_hsts(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_an_https_response_announces_hsts_across_shop_subdomains(): void
    {
        $response = $this->get(str_replace('http://', 'https://', route('login')));

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains',
        );
    }

    /**
     * The middleware is registered outermost precisely so a request rejected
     * deeper in the stack is still answered with the headers.
     */
    public function test_an_error_response_carries_the_headers_too(): void
    {
        $response = $this->get('/a-route-that-does-not-exist');

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_a_header_the_response_already_set_is_left_alone(): void
    {
        Route::get('/__test-preset-header', static fn () => response('ok')
            ->withHeaders(['X-Frame-Options' => 'SAMEORIGIN']));

        $response = $this->get('/__test-preset-header');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
