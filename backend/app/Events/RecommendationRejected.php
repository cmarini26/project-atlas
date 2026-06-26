<?php

namespace App\Events;

use App\Models\Approval;
use App\Models\Recommendation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecommendationRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Recommendation $recommendation,
        public readonly Approval $approval,
    ) {}
}
