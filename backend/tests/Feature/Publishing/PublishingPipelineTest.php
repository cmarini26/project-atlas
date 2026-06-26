<?php

namespace Tests\Feature\Publishing;

use App\Events\CampaignPublished;
use App\Events\RecommendationApproved;
use App\Jobs\PublishCampaign;
use App\Jobs\PublishContent;
use App\Listeners\TriggerCampaignPublishing;
use App\Models\Approval;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\Exceptions\ContentPolicyViolationException;
use App\Services\Publishing\ExecutionService;
use App\Services\Publishing\FakeChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PublishingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    private Recommendation $recommendation;

    private FakeChannelPublisher $fakePublisher;

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
            'title' => 'Silver Age',
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

        $this->recommendation = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_id' => $this->campaign->id,
            'rationale_display' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'status' => 'approved',
        ]);

        // Wire FakeChannelPublisher
        $this->fakePublisher = new FakeChannelPublisher();
        $registry = new ChannelPublisherRegistry();
        $registry->register($this->fakePublisher);
        $this->app->instance(ChannelPublisherRegistry::class, $registry);
    }

    private function makeApprovedAsset(): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Email body.',
            'status' => 'approved',
        ]);
    }

    private function makeApproval(): Approval
    {
        $user = User::factory()->create();

        return Approval::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Recommendation::class,
            'approvable_id' => $this->recommendation->id,
            'user_id' => $user->id,
            'action' => 'approved',
            'acted_at' => now(),
        ]);
    }

    public function test_recommendation_approved_dispatches_publish_campaign_job(): void
    {
        Bus::fake([PublishCampaign::class]);

        $event = new RecommendationApproved($this->recommendation, $this->makeApproval());
        $listener = $this->app->make(TriggerCampaignPublishing::class);
        $listener->handle($event);

        Bus::assertDispatched(PublishCampaign::class, function (PublishCampaign $job): bool {
            return $job->campaign->id === $this->campaign->id;
        });
    }

    public function test_full_pipeline_recommendation_to_campaign_published(): void
    {
        Event::fake([CampaignPublished::class]);

        $this->makeApprovedAsset();

        $executionService = $this->app->make(ExecutionService::class);
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        // Step 1: PublishCampaign queues executions (jobs dispatched synchronously in test)
        $executions = $executionService->queueForCampaign($this->campaign);

        $this->assertCount(1, $executions);

        // Step 2: Run PublishContent for each execution synchronously
        foreach ($executions as $execution) {
            $job = new PublishContent($execution);
            $job->handle($registry, $executionService);
        }

        // Campaign should now be published
        $this->campaign->refresh();
        $this->assertEquals('published', $this->campaign->status);
        Event::assertDispatched(CampaignPublished::class);
    }

    public function test_failed_channel_does_not_block_other_channels(): void
    {
        Event::fake([CampaignPublished::class]);

        $channel2 = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'sms', 'name' => 'SMS', 'is_active' => true,
        ]);

        $this->makeApprovedAsset();
        ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $channel2->id,
            'type' => 'sms',
            'body' => 'SMS body.',
            'status' => 'approved',
        ]);

        $executionService = $this->app->make(ExecutionService::class);
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        $executions = $executionService->queueForCampaign($this->campaign);

        // Run first execution through the publisher (succeeds via fake default)
        $job1 = new PublishContent($executions[0]);
        $job1->handle($registry, $executionService);

        // Manually fail the second execution (simulating a non-retryable failure)
        $executions[1]->refresh();
        $executionService->markFailed($executions[1], 'Policy violation');

        $this->campaign->refresh();
        $this->assertEquals('published', $this->campaign->status);
    }

    public function test_all_failed_executions_settle_campaign_as_cancelled(): void
    {
        Event::fake([CampaignPublished::class]);

        $this->makeApprovedAsset();
        $this->fakePublisher->queueFailure(new ContentPolicyViolationException('Policy'));

        $executionService = $this->app->make(ExecutionService::class);
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        $executions = $executionService->queueForCampaign($this->campaign);

        foreach ($executions as $execution) {
            $job = new PublishContent($execution);
            try {
                $job->handle($registry, $executionService);
            } catch (\Throwable) {
                $execution->refresh();
                if ($execution->status !== 'failed') {
                    $executionService->markFailed($execution, 'Policy');
                }
            }
        }

        $this->campaign->refresh();
        $this->assertEquals('cancelled', $this->campaign->status);
        Event::assertNotDispatched(CampaignPublished::class);
    }
}
