<?php

namespace App\Providers;

use App\Events\ObservationRecorded;
use App\Listeners\DispatchObservationProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(ObservationRecorded::class, DispatchObservationProcessing::class);
    }
}
