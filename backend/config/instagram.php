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

];
