<?php

namespace App\Events;

use App\Models\Integration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntegrationSyncCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Integration $integration,
        public readonly int $observationCount,
    ) {}
}
