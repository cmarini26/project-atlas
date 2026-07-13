<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * What a company wants Atlas's help accomplishing — collected in
 * onboarding's Business Goals step (Milestone 15 Phase 1). Multiple may be
 * selected; stored as a JSON array on OnboardingProfile.business_goals.
 */
enum BusinessGoal: string
{
    use EnumValues;

    case GenerateLeads = 'generate_leads';
    case IncreaseSales = 'increase_sales';
    case PromoteEvents = 'promote_events';
    case IncreaseAwareness = 'increase_awareness';
    case IncreaseWebsiteTraffic = 'increase_website_traffic';
    case ImproveSeo = 'improve_seo';
    case GrowSocialMedia = 'grow_social_media';
    case Other = 'other';
}
