<?php

namespace App\AI\Images;

use App\Models\CampaignImageGeneration;

/**
 * Per-company generation cap over a rolling time window. The approval gate is
 * the real cost control (imagery is generated once at compose, never per edit
 * or per publish); this is a backstop against a runaway loop or abuse.
 */
class ImageGenerationCap
{
    public function limit(): int
    {
        return max(0, (int) config('ai.image.company_cap.limit', 30));
    }

    public function windowHours(): int
    {
        return max(1, (int) config('ai.image.company_cap.window_hours', 24));
    }

    /**
     * Whether generating one more image for the company would exceed the cap.
     * Counts pending and successful generations in the window; a row may be
     * excluded (the caller's own pending ledger row).
     */
    public function wouldExceed(string $companyId, ?string $excludeId = null): bool
    {
        $used = CampaignImageGeneration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', [CampaignImageGeneration::STATUS_PENDING, CampaignImageGeneration::STATUS_READY])
            ->where('created_at', '>=', now()->subHours($this->windowHours()))
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->count();

        return $used >= $this->limit();
    }

    public function message(): string
    {
        return sprintf(
            'Atlas has generated its limit of %d campaign images for your company in the last %d hours. '
            .'New campaigns are still created with copy — approve or archive pending campaigns, or try again later.',
            $this->limit(),
            $this->windowHours(),
        );
    }
}
