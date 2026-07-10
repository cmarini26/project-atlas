<?php

use App\ErrorTracking\Contracts\ErrorTracker;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Http\TrustedProxyResolver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'company' => EnsureCompanyMembership::class,
        ]);

        // Operator-configured, not hardcoded — TRUSTED_PROXIES is unset by
        // default, which trusts no proxies (correct for local/testing,
        // where none exists). Production must set it explicitly once a real
        // reverse proxy/load balancer is provisioned: a comma-separated
        // IP/CIDR list, or the literal "*" to trust whichever machine is
        // directly connecting (the standard choice when the proxy's own IP
        // isn't fixed, e.g. Forge/most managed load balancers). Left unset
        // in production, HTTPS detection, HSTS, client IP resolution, and
        // IP-keyed rate limiting all read from the proxy instead of the real
        // client. See docs/deployment/Production-Topology.md and
        // docs/plans/Critical-Production-Blockers.md, Blocker 7.
        $trustedProxiesEnv = env('TRUSTED_PROXIES');

        $middleware->trustProxies(
            at: (new TrustedProxyResolver())->resolve(is_string($trustedProxiesEnv) ? $trustedProxiesEnv : null),
        );

        // Global (not just 'web'/'api'), so every response — Inertia pages,
        // JSON API responses, and the Filament admin panel (which builds its
        // own middleware list rather than reusing the 'web' group) — gets
        // the same baseline security headers.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Additive to Laravel's own exception logging, not a replacement —
        // reportable() callbacks run alongside the default log-based
        // reporting, never instead of it. Resolves to NullErrorTracker (a
        // no-op) until a real driver is configured; see
        // docs/plans/Critical-Production-Blockers.md Blocker 5.
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorTracker::class)->report($e);
        });
    })->create();
