<?php

namespace Tests\Feature\Brain;

use App\Events\DigitalTwinActivated;
use App\Jobs\ProcessObservation;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Brain\BusinessBrainService;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Covers Milestone 12 Phase 1's Business Brain integration requirement:
 * Instagram-derived Facts must flow into the same Business Brain used by
 * website-derived Facts, through the existing Observe -> Understand
 * pipeline, with no separate brain, bucket, or pipeline for Instagram.
 */
class InstagramBusinessBrainIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_facts_appear_in_the_business_brain_alongside_website_facts(): void
    {
        Event::fake([DigitalTwinActivated::class]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        Catalog::withoutGlobalScopes()->create(['company_id' => $company->id, 'type' => 'mixed']);
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active']);

        // A pre-existing website-derived fact, exactly as WebsiteAnalyst would produce.
        Fact::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'key' => 'business.name',
            'value' => json_encode('CBB Auctions'),
            'data_type' => 'string',
            'confidence' => 95,
            'is_current' => true,
            'valid_from' => now(),
        ]);

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
            'source_type' => 'social',
            'source_identifier' => 'cbb_auctions',
            'raw_payload' => json_encode([
                'account_id' => '17841400000000',
                'username' => 'cbb_auctions',
                'follower_count' => 4210,
                'following_count' => 180,
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        (new ProcessObservation($observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $brain = $this->app->make(BusinessBrainService::class)->for($company);

        $keys = $brain->activeFacts->pluck('key')->all();

        $this->assertContains('business.name', $keys, 'Website-derived fact should still be present.');
        $this->assertContains('instagram.username', $keys, 'Instagram-derived fact should be present.');
        $this->assertContains('instagram.follower_count', $keys);
        $this->assertContains('instagram.following_count', $keys);

        // Same collection, not a separate Instagram-specific bucket.
        $this->assertInstanceOf(Collection::class, $brain->activeFacts);
    }
}
