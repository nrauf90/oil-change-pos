<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCentralHost
{
    public function __construct(private ConfigRepository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configuredHost = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        if (! is_string($configuredHost) || trim($configuredHost) === '') {
            abort(404);
        }

        $configuredHost = strtolower(rtrim($configuredHost, '.'));
        $requestHost = strtolower(rtrim($request->getHost(), '.'));

        if (! hash_equals($configuredHost, $requestHost)) {
            abort(404);
        }

        return $next($request);
    }
}
