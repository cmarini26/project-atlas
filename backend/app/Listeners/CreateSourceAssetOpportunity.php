<?php

namespace App\Listeners;

use App\Events\ObservationProcessed;
use App\Events\OpportunityDetected;
use App\Models\Opportunity;
use App\Models\SourceAsset;

class CreateSourceAssetOpportunity
{
    public function handle(ObservationProcessed $event): void
    {
        $observation = $event->observation;

        if (! str_starts_with($observation->source_identifier, 'source_asset:')) {
            return;
        }

        $asset = SourceAsset::withoutGlobalScopes()->find(
            substr($observation->source_identifier, strlen('source_asset:'))
        );

        if ($asset === null || $asset->company_id !== $observation->company_id) {
            return;
        }

        $asset->update([
            'status' => 'ready',
            'processing_error' => null,
            'processed_at' => now(),
        ]);

        $existing = Opportunity::withoutGlobalScopes()
            ->where('company_id', $asset->company_id)
            ->where('subject_type', 'source_asset')
            ->where('subject_id', $asset->id)
            ->whereIn('status', ['open', 'selected'])
            ->first();

        if ($existing !== null) {
            return;
        }

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $asset->company_id,
            'subject_type' => 'source_asset',
            'subject_id' => $asset->id,
            'type' => $asset->type === 'promotion_event' ? 'urgency' : 'featured_item',
            'title' => "Build a campaign around {$asset->title}",
            'description' => $asset->description ?: "Turn this {$asset->type} asset into a timely customer campaign.",
            'relevance_score' => 90,
            'timing_score' => $asset->ends_at !== null ? 90 : 75,
            'confidence_score' => 95,
            'urgency_score' => $asset->ends_at !== null ? 85 : 50,
            'composite_score' => $asset->ends_at !== null ? 90 : 80,
            'ai_detected' => false,
            'status' => 'open',
            'expires_at' => $asset->ends_at,
            'detected_at' => now(),
        ]);

        OpportunityDetected::dispatch($opportunity);
    }
}
