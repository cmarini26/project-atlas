<?php

namespace App\Events;

use App\Models\Observation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ObservationProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Observation $observation) {}
}
