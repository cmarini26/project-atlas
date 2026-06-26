<?php

namespace App\Events;

use App\Models\Fact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FactExtracted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Fact $fact) {}
}
