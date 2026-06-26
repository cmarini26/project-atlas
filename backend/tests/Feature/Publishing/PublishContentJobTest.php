<?php

namespace Tests\Feature\Publishing;

use App\Events\CampaignPublished;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionFailed;
use App\Jobs\PublishContent;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Opportunity;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\Exceptions\ContentPolicyViolationException;
use App\Services\Publishing\Exceptions\RateLimitException;
use App\Services\Publishing\ExecutionService;
use App\Services\Publishing\FakeChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishContentJobTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

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

        // Wire FakeChannelPublisher into the registry for all tests
        $this->fakePublisher = new FakeChannelPublisher();
        $registry = new ChannelPublisherRegistry();
        $registry->register($this->fakePublisher);
        $this->app->instance(ChannelPublisherRegistry::class, $registry);
    }

    private function makeExecution(string $status = 'queued', ?string $scheduledAt = null): Execution
    {
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Email body.',
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    public function test_success_path_marks_execution_completed(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
    }

    public function test_success_path_logs_attempt(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->assertDatabaseCount('execution_attempts', 1);
        $attempt = ExecutionAttempt::withoutGlobalScopes()->first();
        $this->assertNotNull($attempt);
        $this->assertEquals('completed', $attempt->status);
    }

    public function test_success_path_calls_publisher(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->fakePublisher->assertPublished(1);
    }

    public function test_non_retryable_failure_marks_execution_failed_immediately(): void
    {
        Event::fake([ExecutionFailed::class, CampaignPublished::class]);

        $this->fakePublisher->queueFailure(new ContentPolicyViolationException('Policy violation'));

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);

        try {
            $job->handle(
                $this->app->make(ChannelPublisherRegistry::class),
                $this->app->make(ExecutionService::class),
            );
        } catch (\Throwable) {
            // fail() throws, which is expected
        }

        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
    }

    public function test_retryable_failure_resets_execution_to_queued_and_rethrows(): void
    {
        $this->fakePublisher->queueFailure(new RateLimitException('Rate limit hit'));

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);

        $this->expectException(RateLimitException::class);

        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $execution->refresh();
        $this->assertEquals('queued', $execution->status);
    }

    public function test_retryable_failure_logs_attempt(): void
    {
        $this->fakePublisher->queueFailure(new RateLimitException('Rate limit'));

        $execution = $this->makeExecution();

        $job = new PublishContent($execution);

        try {
            $job->handle(
                $this->app->make(ChannelPublisherRegistry::class),
                $this->app->make(ExecutionService::class),
            );
        } catch (RateLimitException) {
            // expected
        }

        $this->assertDatabaseCount('execution_attempts', 1);
        $attempt = ExecutionAttempt::withoutGlobalScopes()->first();
        $this->assertNotNull($attempt);
        $this->assertEquals('failed', $attempt->status);
    }

    public function test_skips_already_completed_execution(): void
    {
        $execution = $this->makeExecution('completed');

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->fakePublisher->assertNotPublished();
        $this->assertDatabaseCount('execution_attempts', 0);
    }

    public function test_skips_cancelled_execution(): void
    {
        $execution = $this->makeExecution('cancelled');

        $job = new PublishContent($execution);
        $job->handle(
            $this->app->make(ChannelPublisherRegistry::class),
            $this->app->make(ExecutionService::class),
        );

        $this->fakePublisher->assertNotPublished();
    }
}
