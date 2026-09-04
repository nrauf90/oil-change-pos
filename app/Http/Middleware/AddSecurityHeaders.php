<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers for every surface: the counter, both Filament
 * panels, and the streamed invoice and ledger downloads.
 *
 * Deliberately no Content-Security-Policy. Filament and Alpine both evaluate
 * inline expressions, so a policy strict enough to be worth having needs a
 * nonce threaded through every inline block — its own piece of work, not a
 * header this middleware can add without breaking the counter screen.
 */
final readonly class AddSecurityHeaders
{
    /**
     * One year: the shortest max-age the HSTS preload list accepts. `preload`
     * itself is left off, because submitting the domain is a commitment that
     * is slow to undo and belongs to whoever operates the deployment.
     */
    private const HSTS_MAX_AGE = 31536000;

    /** @var array<string, string> */
    private const HEADERS = [
        // Nothing in the application is ever framed, so the strictest value
        // costs nothing and stops a shop subdomain framing the control plane.
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        // A shop slug sits in the host and an invoice number in the path.
        // Neither should travel to a third party in a Referer header.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // Announced only over TLS. A browser ignores it on a plaintext
        // response anyway, and sending it from a local http host would pin
        // that developer's machine to https for a year.
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.self::HSTS_MAX_AGE.'; includeSubDomains',
            );
        }

        return $response;
    }
}
