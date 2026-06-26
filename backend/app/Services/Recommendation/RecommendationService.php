<?php

namespace App\Services\Recommendation;

use App\Events\RecommendationCreated;
use App\Models\Campaign;
use App\Models\Decision;
use App\Models\Recommendation;

class RecommendationService
{
    public function create(Campaign $campaign): Recommendation
    {
        $decision = Decision::withoutGlobalScopes()->findOrFail($campaign->decision_id);

        $rationaleDisplay = $this->buildRationaleDisplay($decision->rationale ?? []);
        $expectedImpact = $decision->expected_impact ?? [];

        $recommendation = Recommendation::create([
            'company_id' => $campaign->company_id,
            'decision_id' => $decision->id,
            'campaign_id' => $campaign->id,
            'rationale_display' => $rationaleDisplay,
            'expected_impact' => $expectedImpact,
            'status' => 'pending',
        ]);

        $decision->update(['status' => 'recommended']);

        RecommendationCreated::dispatch($recommendation);

        return $recommendation;
    }

    /**
     * @param  array<string, mixed>  $rationale
     * @return array<string, string>
     */
    private function buildRationaleDisplay(array $rationale): array
    {
        $display = [];

        if (! empty($rationale['why_now'])) {
            $display['why_now'] = (string) $rationale['why_now'];
        }

        if (! empty($rationale['why_this'])) {
            $display['why_this'] = (string) $rationale['why_this'];
        }

        if (! empty($rationale['why_channel'])) {
            $display['why_channel'] = (string) $rationale['why_channel'];
        }

        if (! empty($rationale['why_works'])) {
            $display['why_works'] = (string) $rationale['why_works'];
        }

        return $display;
    }
}
