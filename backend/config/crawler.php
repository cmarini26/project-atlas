<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Website Crawler Settings
    |--------------------------------------------------------------------------
    |
    | max_pages: Maximum pages to crawl per integration sync. Keep this low
    | for fast initial onboarding syncs. Production can raise it via env.
    |
    */

    'max_pages' => (int) env('CRAWLER_MAX_PAGES', 20),

];
