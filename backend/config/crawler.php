<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Website Crawler Settings
    |--------------------------------------------------------------------------
    |
    | max_pages: Maximum pages for an integration's FIRST sync. Defaults to 1
    | so onboarding produces a first recommendation quickly.
    |
    | recurring_max_pages: Maximum pages for every sync after the first
    | (scheduled re-syncs and manual re-syncs). Deeper than onboarding so the
    | Business Brain keeps deepening over time.
    |
    | connect_timeout: Seconds to wait for a TCP connection. Kept short so
    | unreachable hosts fail fast rather than stalling the HTTP request.
    |
    | request_timeout: Total seconds to wait for a single page response once
    | connected, including response body transfer.
    |
    */

    'max_pages' => (int) env('CRAWLER_MAX_PAGES', 1),

    'recurring_max_pages' => (int) env('CRAWLER_RECURRING_MAX_PAGES', 10),

    'connect_timeout' => (int) env('CRAWLER_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('CRAWLER_REQUEST_TIMEOUT', 10),

];
