<?php

namespace App\Events;

use App\Models\DigitalTwin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DigitalTwinActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly DigitalTwin $twin) {}
}
