<?php

use App\ErrorTracking\Contracts\ErrorTracker;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
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

        // Deployment topology (hosting provider, load balancer) isn't
        // finalized yet (Blocker 7 is still infrastructure-pending), so we
        // trust the immediate calling proxy ('*') rather than a hardcoded IP
        // list — standard guidance for a single-hop reverse proxy (e.g.
        // Forge/nginx) whose own IP isn't known in advance. Revisit once
        // Blocker 7 fixes the actual proxy layer in place.
        $middleware->trustProxies(at: '*');

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
