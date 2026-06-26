<?php

namespace App\Listeners;

use App\Events\ObservationRecorded;
use App\Jobs\ProcessObservation;

class DispatchObservationProcessing
{
    public function handle(ObservationRecorded $event): void
    {
        ProcessObservation::dispatch($event->observation);
    }
}
