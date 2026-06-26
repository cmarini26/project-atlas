<?php

namespace App\Events;

use App\Models\Opportunity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Opportunity $opportunity) {}
}
