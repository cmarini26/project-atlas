<?php

namespace App\Providers;

use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\Website\WebPageCrawler;
use App\Services\Observatory\Connectors\Website\WebsiteConnector;
use Illuminate\Support\ServiceProvider;

class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectorRegistry::class, function (): ConnectorRegistry {
            return new ConnectorRegistry([
                new WebsiteConnector(new WebPageCrawler(
                    maxPages: (int) config('crawler.max_pages', 1),
                    requestTimeout: (int) config('crawler.request_timeout', 10),
                    connectTimeout: (int) config('crawler.connect_timeout', 5),
                )),
            ]);
        });
    }
}
