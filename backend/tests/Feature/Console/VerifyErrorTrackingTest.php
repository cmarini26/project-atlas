<?php

namespace Tests\Feature\Console;

use App\ErrorTracking\Contracts\ErrorTracker;
use App\ErrorTracking\Testing\FakeErrorTracker;
use Tests\TestCase;

class VerifyErrorTrackingTest extends TestCase
{
    public function test_it_refuses_to_send_when_sentry_is_not_active(): void
    {
        config([
            'services.error_tracking.driver' => 'null',
            'services.error_tracking.dsn' => null,
        ]);

        $this->artisan('atlas:verify-error-tracking', ['--send' => true])
            ->expectsOutput('Sentry is not active. Set ERROR_TRACKING_DRIVER=sentry and ERROR_TRACKING_DSN.')
            ->assertFailed();
    }

    public function test_it_requires_an_explicit_send_option(): void
    {
        config([
            'services.error_tracking.driver' => 'sentry',
            'services.error_tracking.dsn' => 'https://public@example.ingest.sentry.io/1',
        ]);

        $this->artisan('atlas:verify-error-tracking')
            ->expectsOutput('Sentry configuration is present. Re-run with --send to emit the controlled verification event.')
            ->assertExitCode(2);
    }

    public function test_it_submits_one_controlled_verification_event(): void
    {
        config([
            'services.error_tracking.driver' => 'sentry',
            'services.error_tracking.dsn' => 'https://public@example.ingest.sentry.io/1',
        ]);

        $tracker = new FakeErrorTracker();
        $this->app->instance(ErrorTracker::class, $tracker);

        $this->artisan('atlas:verify-error-tracking', ['--send' => true])
            ->expectsOutput('Controlled verification event submitted to Sentry.')
            ->assertSuccessful();

        $this->assertSame(1, $tracker->reportedCount());
    }
}
