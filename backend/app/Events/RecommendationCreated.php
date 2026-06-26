<?php

namespace App\Events;

use App\Models\Recommendation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecommendationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Recommendation $recommendation) {}
}
