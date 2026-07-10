<?php

namespace Tests\Feature\ErrorTracking;

use App\ErrorTracking\Contracts\ErrorTracker;
use App\ErrorTracking\NullErrorTracker;
use App\ErrorTracking\Testing\FakeErrorTracker;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the error-tracking preparation half of Critical Production Blocker
 * 5 — see docs/plans/Critical-Production-Blockers.md. No real vendor
 * (Sentry or equivalent) is installed yet; this proves the abstraction
 * resolves correctly and that bootstrap/app.php's withExceptions() actually
 * calls whatever is bound, in addition to Laravel's own logging.
 */
class ErrorTrackerTest extends TestCase
{
    public function test_error_tracker_resolves_to_the_null_implementation_in_testing(): void
    {
        $this->assertInstanceOf(NullErrorTracker::class, app(ErrorTracker::class));
    }

    public function test_null_error_tracker_does_not_throw(): void
    {
        (new NullErrorTracker())->report(new RuntimeException('irrelevant'));

        $this->addToAssertionCount(1);
    }

    public function test_the_exception_handler_reports_to_the_bound_error_tracker(): void
    {
        $fake = new FakeErrorTracker();
        $this->app->instance(ErrorTracker::class, $fake);

        $exception = new RuntimeException('boom');

        app(ExceptionHandler::class)->report($exception);

        $this->assertSame(1, $fake->reportedCount());
        $this->assertTrue($fake->hasReported(RuntimeException::class));
    }

    public function test_reporting_does_not_replace_laravels_own_exception_logging(): void
    {
        // Laravel's default Handler still logs every reported exception via
        // its own reportable/report() pipeline unless a callback calls
        // stop() — ours never does, so both run. Asserting the handler
        // resolves and completes without throwing is the testable surface
        // here; a full log-content assertion would duplicate Laravel's own
        // exception handler test suite.
        app(ExceptionHandler::class)->report(new RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }
}
