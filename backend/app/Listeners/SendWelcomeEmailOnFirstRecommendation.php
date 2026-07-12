<?php

namespace App\Listeners;

use App\Events\RecommendationCreated;
use App\Models\CompanyMembership;
use App\Models\Recommendation;
use App\Notifications\FirstRecommendationReady;

class SendWelcomeEmailOnFirstRecommendation
{
    public function handle(RecommendationCreated $event): void
    {
        $recommendation = $event->recommendation;

        $isFirstForCompany = Recommendation::withoutGlobalScopes()
            ->where('company_id', $recommendation->company_id)
            ->where('id', '!=', $recommendation->id)
            ->doesntExist();

        if (! $isFirstForCompany) {
            return;
        }

        $owner = CompanyMembership::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $recommendation->company_id)
            ->where('role', 'owner')
            ->first();

        if ($owner === null || $owner->user === null) {
            return;
        }

        $owner->user->notify(new FirstRecommendationReady($recommendation));
    }
}
