<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\CampaignPreparationPrompt;
use App\AI\StructuredResponseParser;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Decision;
use App\Services\Analyst\Contracts\Analyst;

class CampaignPreparationAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
    ) {}

    public function analyze(Decision $decision, BusinessBrain $brain): CampaignBlueprint
    {
        $prompt = new CampaignPreparationPrompt($decision, $brain);

        $response = $this->ai->complete($prompt);

        $data = $this->parser->parse($response);

        return CampaignBlueprint::fromArray($data);
    }
}
