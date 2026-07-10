<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Baseline CSP only. It restricts framing, plugin/object embeds, and
     * <base> tag injection — none of which Inertia, Vite-built assets, or
     * Filament rely on — without touching script-src/style-src/connect-src.
     * Filament/Livewire/Alpine use inline scripts and styles, and local
     * development loads assets from the Vite dev server on a different
     * origin/port; restricting those sources is a larger, nonce-based
     * rollout deliberately deferred here (see Critical-Production-Blockers.md
     * Blocker 3).
     */
    private const CSP = "frame-ancestors 'none'; object-src 'none'; base-uri 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', self::CSP);

        // Only sent over a connection Laravel considers secure — via direct
        // TLS or, once TrustProxies forwards X-Forwarded-Proto, behind a
        // proxy. Sending HSTS over plain HTTP isn't harmful, but it is
        // meaningless there, so we don't send a header with no effect.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
