<?php

namespace App\ErrorTracking;

use App\ErrorTracking\Contracts\ErrorTracker;
use Throwable;

/**
 * The default binding whenever no real error-tracking vendor is configured
 * (ERROR_TRACKING_DRIVER=null, the default in every environment, including
 * production until a real driver is wired in). Deliberately a no-op: Laravel's
 * own exception logging already runs regardless of this binding, so this
 * class exists only to give bootstrap/app.php's withExceptions() a concrete
 * ErrorTracker to call without needing a vendor package installed yet. See
 * docs/plans/Critical-Production-Blockers.md, Blocker 5.
 */
class NullErrorTracker implements ErrorTracker
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void
    {
        // Intentionally inert.
    }
}
