<?php

use App\Providers\AnalyticsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ImagingServiceProvider;
use App\Providers\LearningServiceProvider;
use App\Providers\MarketingHealthServiceProvider;
use App\Providers\PublisherServiceProvider;

return [
    AppServiceProvider::class,
    AnalyticsServiceProvider::class,
    BillingServiceProvider::class,
    ConnectorServiceProvider::class,
    AdminPanelProvider::class,
    PublisherServiceProvider::class,
    ImagingServiceProvider::class,
    LearningServiceProvider::class,
    MarketingHealthServiceProvider::class,
];
