<?php

namespace App\Providers;

use App\Services\MarketingHealth\MarketingHealthRegistry;
use App\Services\MarketingHealth\Scorers\BrandConsistencyScorer;
use App\Services\MarketingHealth\Scorers\CampaignConsistencyScorer;
use App\Services\MarketingHealth\Scorers\ContentDiversityScorer;
use App\Services\MarketingHealth\Scorers\CtaStrengthScorer;
use App\Services\MarketingHealth\Scorers\PresenceCoverageScorer;
use App\Services\MarketingHealth\Scorers\SocialActivityScorer;
use App\Services\MarketingHealth\Scorers\WebsiteHealthScorer;
use Illuminate\Support\ServiceProvider;

class MarketingHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketingHealthRegistry::class, function ($app): MarketingHealthRegistry {
            return new MarketingHealthRegistry([
                $app->make(WebsiteHealthScorer::class),
                $app->make(SocialActivityScorer::class),
                $app->make(CampaignConsistencyScorer::class),
                $app->make(BrandConsistencyScorer::class),
                $app->make(ContentDiversityScorer::class),
                $app->make(CtaStrengthScorer::class),
                $app->make(PresenceCoverageScorer::class),
            ]);
        });
    }
}
