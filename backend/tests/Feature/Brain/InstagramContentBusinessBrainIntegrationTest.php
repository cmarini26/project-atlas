<?php

namespace Tests\Feature\Brain;

use App\Events\DigitalTwinActivated;
use App\Jobs\ProcessObservation;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Brain\BusinessBrainService;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use App\Services\MarketingHealth\MarketingHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Covers Milestone 12 Phase 2's Business Brain integration requirement:
 * content-derived Instagram Facts must flow into the same Business Brain
 * as everything else, with no special handling — mirrors
 * InstagramBusinessBrainIntegrationTest (Phase 1, profile facts).
 */
class InstagramContentBusinessBrainIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_facts_appear_in_the_business_brain_alongside_other_facts(): void
    {
        Event::fake([DigitalTwinActivated::class]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        Catalog::withoutGlobalScopes()->create(['company_id' => $company->id, 'type' => 'mixed']);
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active']);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token-123'],
            'status' => 'active',
        ]);

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'source_type' => 'social_content',
            'source_identifier' => 'cbb_auctions-recent-media',
            'raw_payload' => json_encode([
                'posts' => [
                    ['id' => '1', 'caption' => 'Shop now', 'timestamp' => '2026-07-01T00:00:00+00:00', 'media_type' => 'IMAGE', 'hashtags' => ['comics'], 'mentions' => []],
                    ['id' => '2', 'caption' => 'New arrivals', 'timestamp' => '2026-07-05T00:00:00+00:00', 'media_type' => 'VIDEO', 'hashtags' => [], 'mentions' => []],
                ],
                'media_limit' => 20,
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        (new ProcessObservation($observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
            $this->app->make(MarketingHealthService::class),
        );

        $brain = $this->app->make(BusinessBrainService::class)->for($company);

        $keys = $brain->activeFacts->pluck('key')->all();

        $this->assertContains('instagram.media_mix', $keys);
        $this->assertContains('instagram.hashtag_usage', $keys);
        $this->assertContains('instagram.cta_usage', $keys);
        $this->assertContains('instagram.content_distribution', $keys);
        $this->assertContains('instagram.posting_cadence', $keys);
    }
}
