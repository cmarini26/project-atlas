<?php

use App\Providers\AnalyticsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\PublisherServiceProvider;

return [
    AppServiceProvider::class,
    AnalyticsServiceProvider::class,
    ConnectorServiceProvider::class,
    AdminPanelProvider::class,
    PublisherServiceProvider::class,
];
