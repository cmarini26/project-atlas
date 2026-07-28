<?php

namespace App\Console\Commands;

use App\ErrorTracking\Contracts\ErrorTracker;
use Illuminate\Console\Command;
use RuntimeException;

use function Sentry\flush;

class VerifyErrorTracking extends Command
{
    protected $signature = 'atlas:verify-error-tracking
        {--send : Send one controlled, non-sensitive verification exception}';

    protected $description = 'Verify the configured production error-tracking integration';

    public function handle(ErrorTracker $tracker): int
    {
        if (config('services.error_tracking.driver') !== 'sentry' || blank(config('services.error_tracking.dsn'))) {
            $this->error('Sentry is not active. Set ERROR_TRACKING_DRIVER=sentry and ERROR_TRACKING_DSN.');

            return self::FAILURE;
        }

        if (! $this->option('send')) {
            $this->warn('Sentry configuration is present. Re-run with --send to emit the controlled verification event.');

            return self::INVALID;
        }

        $tracker->report(
            new RuntimeException('Atlas controlled error-tracking verification'),
            [
                'component' => 'error_tracking',
                'verification' => true,
            ],
        );
        flush();

        $this->info('Controlled verification event submitted to Sentry.');

        return self::SUCCESS;
    }
}
