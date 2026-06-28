<?php

namespace Tests\Feature\Campaign;

use App\Events\RecommendationApproved;
use App\Events\RecommendationRejected;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Recommendation\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $service;

    private Company $company;

    private User $user;

    private Campaign $campaign;

    private Recommendation $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ApprovalService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->user = User::factory()->create();

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

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$channel->id],
            'rationale' => ['why_now' => 'Auction closing.'],
            'expected_impact' => ['summary' => '15% lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Silver Age Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'draft',
        ]);

        ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $channel->id,
            'type' => 'email',
            'body' => 'Email body content here.',
            'status' => 'draft',
        ]);

        $this->recommendation = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_id' => $this->campaign->id,
            'rationale_display' => ['why_now' => 'Auction closing.'],
            'expected_impact' => ['summary' => '15% lift'],
            'status' => 'pending',
        ]);
    }

    public function test_approve_transitions_recommendation_to_approved(): void
    {
        $this->service->approve($this->recommendation, $this->user);

        $this->recommendation->refresh();
        $this->assertEquals('approved', $this->recommendation->status);
        $this->assertNotNull($this->recommendation->responded_at);
    }

    public function test_approve_transitions_campaign_to_approved(): void
    {
        Event::fake([RecommendationApproved::class]);

        $this->service->approve($this->recommendation, $this->user);

        $this->campaign->refresh();
        $this->assertEquals('approved', $this->campaign->status);
    }

    public function test_approve_transitions_draft_content_assets_to_approved(): void
    {
        $this->service->approve($this->recommendation, $this->user);

        $assets = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $this->campaign->id)
            ->get();

        foreach ($assets as $asset) {
            $this->assertEquals('approved', $asset->status);
        }
    }

    public function test_approve_creates_approval_record(): void
    {
        $approval = $this->service->approve($this->recommendation, $this->user, 'Looks great!');

        $this->assertEquals('approved', $approval->action);
        $this->assertEquals($this->user->id, $approval->user_id);
        $this->assertEquals('Looks great!', $approval->notes);
        $this->assertNotNull($approval->acted_at);
    }

    public function test_approve_fires_recommendation_approved_event(): void
    {
        Event::fake([RecommendationApproved::class]);

        $this->service->approve($this->recommendation, $this->user);

        Event::assertDispatched(RecommendationApproved::class, function (RecommendationApproved $event): bool {
            return $event->recommendation->id === $this->recommendation->id;
        });
    }

    public function test_reject_transitions_recommendation_to_rejected(): void
    {
        $this->service->reject($this->recommendation, $this->user, 'Not the right time.');

        $this->recommendation->refresh();
        $this->assertEquals('rejected', $this->recommendation->status);
        $this->assertNotNull($this->recommendation->responded_at);
    }

    public function test_reject_transitions_campaign_to_cancelled(): void
    {
        $this->service->reject($this->recommendation, $this->user);

        $this->campaign->refresh();
        $this->assertEquals('cancelled', $this->campaign->status);
    }

    public function test_reject_archives_draft_content_assets(): void
    {
        $this->service->reject($this->recommendation, $this->user);

        $assets = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $this->campaign->id)
            ->get();

        foreach ($assets as $asset) {
            $this->assertEquals('archived', $asset->status);
        }
    }

    public function test_reject_fires_recommendation_rejected_event(): void
    {
        Event::fake([RecommendationRejected::class]);

        $this->service->reject($this->recommendation, $this->user);

        Event::assertDispatched(RecommendationRejected::class, function (RecommendationRejected $event): bool {
            return $event->recommendation->id === $this->recommendation->id;
        });
    }

    public function test_cannot_approve_already_approved_recommendation(): void
    {
        $this->recommendation->update(['status' => 'approved']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot approve recommendation with status: approved');

        $this->service->approve($this->recommendation, $this->user);
    }

    public function test_cannot_reject_already_rejected_recommendation(): void
    {
        $this->recommendation->update(['status' => 'rejected']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot reject recommendation with status: rejected');

        $this->service->reject($this->recommendation, $this->user);
    }

    public function test_no_publishing_happens_on_approve(): void
    {
        $this->service->approve($this->recommendation, $this->user);

        $assets = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $this->campaign->id)
            ->get();

        foreach ($assets as $asset) {
            $this->assertNotEquals('published', $asset->status);
            $this->assertNull($asset->published_at);
        }
    }
}
