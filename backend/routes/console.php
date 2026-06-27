<?php

use App\Jobs\ApplyLearnings;
use App\Jobs\CheckChannelHealth;
use App\Jobs\PruneRawMetrics;
use App\Jobs\PublishScheduledContent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PublishScheduledContent())->everyFiveMinutes();
Schedule::job(new CheckChannelHealth())->everyThirtyMinutes();
Schedule::job(new PruneRawMetrics())->monthly();
Schedule::job(new ApplyLearnings())->dailyAt('02:00');
