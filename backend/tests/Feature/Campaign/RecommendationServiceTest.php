<?php

namespace Tests\Feature\Campaign;

use App\Events\RecommendationCreated;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecommendationService $service;

    private Company $company;

    private Campaign $campaign;

    private Decision $decision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(RecommendationService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age Collection',
            'description' => 'Test',
            'relevance_score' => 80,
            'timing_score' => 75,
            'confidence_score' => 70,
            'urgency_score' => 65,
            'composite_score' => 73,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $this->decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$channel->id],
            'rationale' => [
                'why_now' => 'Auction closes in 7 days.',
                'why_this' => 'Rare Silver Age collection.',
                'why_channel' => 'Email converts best.',
                'why_works' => 'Subscribers have bought similar items.',
                'expected_impact' => [
                    'summary' => '15% lift in registrations',
                    'reach_estimate' => '2,000 subscribers',
                    'engagement_signal' => '25% open rate',
                    'confidence_basis' => 'Past data',
                ],
            ],
            'expected_impact' => ['summary' => '15% lift'],
            'confidence_score' => 70,
            'status' => 'pending',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $this->decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Silver Age Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_creates_pending_recommendation(): void
    {
        $recommendation = $this->service->create($this->campaign);

        $this->assertEquals('pending', $recommendation->status);
        $this->assertEquals($this->company->id, $recommendation->company_id);
        $this->assertEquals($this->decision->id, $recommendation->decision_id);
        $this->assertEquals($this->campaign->id, $recommendation->campaign_id);
    }

    public function test_builds_rationale_display_from_decision(): void
    {
        $recommendation = $this->service->create($this->campaign);

        $this->assertIsArray($recommendation->rationale_display);
        $this->assertArrayHasKey('why_now', $recommendation->rationale_display);
        $this->assertNotEmpty($recommendation->rationale_display['why_now']);
    }

    public function test_updates_decision_status_to_recommended(): void
    {
        $this->service->create($this->campaign);

        $this->decision->refresh();
        $this->assertEquals('recommended', $this->decision->status);
    }

    public function test_fires_recommendation_created_event(): void
    {
        Event::fake([RecommendationCreated::class]);

        $recommendation = $this->service->create($this->campaign);

        Event::assertDispatched(RecommendationCreated::class, function (RecommendationCreated $event) use ($recommendation): bool {
            return $event->recommendation->id === $recommendation->id;
        });
    }

    public function test_copies_expected_impact_from_decision(): void
    {
        $recommendation = $this->service->create($this->campaign);

        $this->assertIsArray($recommendation->expected_impact);
        $this->assertArrayHasKey('summary', $recommendation->expected_impact);
    }

    public function test_copies_campaign_type_from_campaign(): void
    {
        // The recommendation must carry its own campaign_type: the UI renders it
        // (a null crashed the recommendations page) and ApprovalService reads it
        // when publishing. It was previously left null.
        $recommendation = $this->service->create($this->campaign);

        $this->assertSame('featured_item', $recommendation->campaign_type);
    }
}
