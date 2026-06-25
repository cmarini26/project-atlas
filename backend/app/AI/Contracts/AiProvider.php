<?php

namespace App\AI\Contracts;

use App\AI\AiResponse;
use App\AI\Prompts\Prompt;

interface AiProvider
{
    public function complete(Prompt $prompt): AiResponse;
}
