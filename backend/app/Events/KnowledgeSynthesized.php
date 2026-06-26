<?php

namespace App\Events;

use App\Models\Knowledge;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KnowledgeSynthesized
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Knowledge $knowledge) {}
}
