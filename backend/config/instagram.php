<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instagram Graph API Settings
    |--------------------------------------------------------------------------
    |
    | base_url: Instagram Graph API host. Overridable for testing against a
    | mock server; production has no reason to change it.
    |
    | connect_timeout / request_timeout: as in config/crawler.php — kept
    | short so an unreachable or slow API fails fast rather than stalling
    | the sync job.
    |
    | Per-company credentials (the access token) are NOT configured here —
    | they live encrypted on each company's Integration.config, entered when
    | the company connects their account. This file only configures the API
    | endpoint itself, which is the same for every company.
    |
    */

    'base_url' => env('INSTAGRAM_GRAPH_BASE_URL', 'https://graph.instagram.com'),

    'connect_timeout' => (int) env('INSTAGRAM_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('INSTAGRAM_REQUEST_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Recent post retrieval (Milestone 12 Phase 2)
    |--------------------------------------------------------------------------
    |
    | media_limit: how many of the account's most recent posts to fetch on
    | each sync for content-intelligence facts (posting cadence, media mix,
    | hashtag usage, etc.). Configurable per the spec; 20 is a reasonable
    | default that covers roughly a month of typical posting activity
    | without an excessively large API response.
    |
    */

    'media_limit' => (int) env('INSTAGRAM_MEDIA_LIMIT', 20),

];
