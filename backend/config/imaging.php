<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generated visual assets (SCRUM-71)
    |--------------------------------------------------------------------------
    |
    | Atlas can propose one AI-generated image alongside generated copy for a
    | narrow set of visual channel types. The feature is OFF by default: until
    | a real hosted image provider is selected, only the `fake` provider is
    | registered, and nothing calls it unless `enabled` is explicitly true.
    |
    */

    'enabled' => env('IMAGE_GENERATION_ENABLED', false),

    'provider' => env('IMAGE_GENERATION_PROVIDER', 'fake'),

    'disk' => env('IMAGE_GENERATION_DISK', 'public'),

    // Channel types eligible for a generated image proposal. Deliberately
    // narrow for the first slice — no email or landing-page hero images.
    'channels' => ['instagram', 'facebook', 'blog'],

    // Cost guardrail: the most generated images one company can accrue in a
    // rolling calendar day before Atlas stops proposing new ones.
    'per_company_daily_limit' => (int) env('IMAGE_GENERATION_DAILY_LIMIT', 20),

];
