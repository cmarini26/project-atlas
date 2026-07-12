<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing Health — Milestone 13 Phase 1
    |--------------------------------------------------------------------------
    |
    | Per-dimension scoring constants. Deliberately configuration, not code,
    | so they can be tuned from real evidence later without touching scorer
    | logic — see docs/specs/Marketing-Health.md §3.
    |
    */

    'website' => [
        // Days since the last successful crawl at which recency scores 100,
        // decaying linearly to 0 by 'stale_after_days'.
        'fresh_within_days' => (int) env('MARKETING_HEALTH_WEBSITE_FRESH_DAYS', 3),
        'stale_after_days' => (int) env('MARKETING_HEALTH_WEBSITE_STALE_DAYS', 30),
        // Weights for the three inputs (recency, core-fact presence, crawl
        // success rate); must sum to 1.0.
        'recency_weight' => 0.5,
        'core_facts_weight' => 0.3,
        'success_rate_weight' => 0.2,
    ],

    'social_activity' => [
        // Target posts/week at which cadence scores 100.
        'target_posts_per_week' => (float) env('MARKETING_HEALTH_SOCIAL_TARGET_CADENCE', 2.0),
    ],

    'campaign_consistency' => [
        // Days since the last campaign at which the score is still 100.
        'full_score_within_days' => (int) env('MARKETING_HEALTH_CAMPAIGN_FULL_DAYS', 7),
        // Days since the last campaign at which the score reaches 0.
        'zero_score_after_days' => (int) env('MARKETING_HEALTH_CAMPAIGN_CEILING_DAYS', 60),
    ],

    'presence_coverage' => [
        // Importance weighting when computing weighted channel coverage.
        'importance_weights' => [
            'primary' => 2.0,
            'secondary' => 1.0,
            'experimental' => 0.5,
        ],
    ],

];
