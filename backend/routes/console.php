<?php

use App\Jobs\ApplyLearnings;
use App\Jobs\CheckChannelHealth;
use App\Jobs\ExpireOpportunities;
use App\Jobs\PruneRawMetrics;
use App\Jobs\PublishScheduledContent;
use App\Jobs\SendFeedbackDigest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() on every entry below guards against a slow run still
// executing when the next tick fires; onOneServer() additionally guards
// against duplicate dispatch if schedule:run is ever invoked from more than
// one server, for jobs that aren't already deduped via ShouldBeUnique.

// The recurring Observe → Learn loop: re-sync integrations whose
// next_run_at has passed so the Business Brain keeps learning.
Schedule::command('atlas:sync-due-integrations')->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Expired opportunities must leave 'open' promptly — the engine's dedupe
// ignores expired rows, so expiry is what allows re-detection.
Schedule::job(new ExpireOpportunities())->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new PublishScheduledContent())->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::job(new CheckChannelHealth())->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::job(new PruneRawMetrics())->monthly()
    ->withoutOverlapping()
    ->onOneServer();
// ApplyLearnings implements ShouldBeUnique (one run per company per day),
// so onOneServer() would be redundant — withoutOverlapping() still guards
// the schedule tick itself.
Schedule::job(new ApplyLearnings())->dailyAt('02:00')
    ->withoutOverlapping();

// Weekly NPS digest for the team — Mondays, before the week's standup.
Schedule::job(new SendFeedbackDigest())->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->onOneServer();
