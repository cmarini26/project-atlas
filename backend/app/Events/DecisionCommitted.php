<?php

namespace App\Events;

use App\Models\Decision;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DecisionCommitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Decision $decision) {}
}
