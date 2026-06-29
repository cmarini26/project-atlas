<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Website Crawler Settings
    |--------------------------------------------------------------------------
    |
    | max_pages: Maximum pages to crawl per integration sync. Defaults to 1
    | so onboarding form submission returns quickly. Set CRAWLER_MAX_PAGES=20
    | in production for a thorough crawl on recurring syncs.
    |
    | connect_timeout: Seconds to wait for a TCP connection. Kept short so
    | unreachable hosts fail fast rather than stalling the HTTP request.
    |
    | request_timeout: Total seconds to wait for a single page response once
    | connected, including response body transfer.
    |
    */

    'max_pages' => (int) env('CRAWLER_MAX_PAGES', 1),

    'connect_timeout' => (int) env('CRAWLER_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('CRAWLER_REQUEST_TIMEOUT', 10),

];
