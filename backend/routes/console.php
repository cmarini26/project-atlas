<?php

use App\Jobs\ApplyLearnings;
use App\Jobs\CheckChannelHealth;
use App\Jobs\ExpireOpportunities;
use App\Jobs\PruneRawMetrics;
use App\Jobs\PublishScheduledContent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The recurring Observe → Learn loop: re-sync integrations whose
// next_run_at has passed so the Business Brain keeps learning.
Schedule::command('atlas:sync-due-integrations')->everyFifteenMinutes();

// Expired opportunities must leave 'open' promptly — the engine's dedupe
// ignores expired rows, so expiry is what allows re-detection.
Schedule::job(new ExpireOpportunities())->hourly();

Schedule::job(new PublishScheduledContent())->everyFiveMinutes();
Schedule::job(new CheckChannelHealth())->everyThirtyMinutes();
Schedule::job(new PruneRawMetrics())->monthly();
Schedule::job(new ApplyLearnings())->dailyAt('02:00');
