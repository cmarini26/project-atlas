<?php

namespace App\Services\Http;

/**
 * Parses the TRUSTED_PROXIES env value into whatever
 * `Illuminate\Foundation\Configuration\Middleware::trustProxies(at: ...)`
 * expects. Pulled out of bootstrap/app.php so the parsing itself is
 * unit-testable — bootstrap/app.php's env() read happens once at
 * application boot, too early in the test lifecycle to vary per test. See
 * docs/deployment/Production-Topology.md and
 * docs/plans/Critical-Production-Blockers.md, Blocker 7.
 */
class TrustedProxyResolver
{
    /**
     * @return array<int, string>|string|null
     */
    public function resolve(?string $raw): array|string|null
    {
        return match (true) {
            $raw === null || $raw === '' => null,
            $raw === '*' => '*',
            default => array_map('trim', explode(',', $raw)),
        };
    }
}
