<?php

namespace Tests\Feature\Publishing;

use App\Jobs\PublishCampaign;
use App\Jobs\PublishContent;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Publishing\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PublishCampaignJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Test',
            'description' => 'Desc',
            'relevance_score' => 80,
            'timing_score' => 75,
            'confidence_score' => 70,
            'urgency_score' => 65,
            'composite_score' => 73,
            'status' => 'selected',
            'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'approved',
        ]);
    }

    private function makeApprovedAsset(?string $scheduledAt = null): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Body content.',
            'status' => 'approved',
            'scheduled_at' => $scheduledAt,
        ]);
    }

    public function test_creates_executions_for_approved_assets(): void
    {
        Bus::fake([PublishContent::class]);

        $this->makeApprovedAsset();
        $this->makeApprovedAsset();

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        $this->assertDatabaseCount('executions', 2);
    }

    public function test_dispatches_publish_content_for_immediate_executions(): void
    {
        Bus::fake([PublishContent::class]);

        $this->makeApprovedAsset(); // no scheduled_at → immediate

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        Bus::assertDispatched(PublishContent::class, 1);
    }

    public function test_does_not_dispatch_publish_content_for_scheduled_executions(): void
    {
        Bus::fake([PublishContent::class]);

        $this->makeApprovedAsset(now()->addHour()->toDateTimeString()); // scheduled

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        Bus::assertNotDispatched(PublishContent::class);
    }

    public function test_skips_campaign_not_in_approved_status(): void
    {
        Bus::fake([PublishContent::class]);

        $this->campaign->update(['status' => 'draft']);
        $this->makeApprovedAsset();

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        $this->assertDatabaseCount('executions', 0);
        Bus::assertNotDispatched(PublishContent::class);
    }

    public function test_handles_campaign_with_no_approved_assets_gracefully(): void
    {
        Bus::fake([PublishContent::class]);

        // No assets created

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        $this->assertDatabaseCount('executions', 0);
        Bus::assertNotDispatched(PublishContent::class);
    }

    public function test_executions_are_dispatched_on_high_queue(): void
    {
        Bus::fake([PublishContent::class]);

        $this->makeApprovedAsset();

        $job = new PublishCampaign($this->campaign);
        $job->handle($this->app->make(ExecutionService::class));

        Bus::assertDispatched(PublishContent::class, function (PublishContent $job): bool {
            return $job->queue() === 'high';
        });
    }
}
