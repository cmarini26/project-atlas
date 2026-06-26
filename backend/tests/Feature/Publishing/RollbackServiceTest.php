<?php

namespace Tests\Feature\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Contracts\SupportsRollback;
use App\Services\Publishing\FakeChannelPublisher;
use App\Services\Publishing\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RollbackServiceTest extends TestCase
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
            'status' => 'published',
        ]);
    }

    private function makeCompletedExecution(): Execution
    {
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Email body.',
            'status' => 'published',
        ]);

        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'completed',
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    public function test_log_channel_publisher_is_not_rollable_in_m6(): void
    {
        $fakePublisher = new FakeChannelPublisher();
        $registry = new ChannelPublisherRegistry();
        $registry->register($fakePublisher);

        $service = new RollbackService($registry);

        $this->makeCompletedExecution();

        $result = $service->rollback($this->campaign);

        $this->assertCount(1, $result['unrollable']);
        $this->assertCount(0, $result['rolled_back']);
        $this->assertCount(0, $result['failed']);
    }

    public function test_rollable_publisher_archives_asset_on_success(): void
    {
        $rollablePublisher = new class() implements ChannelPublisher, SupportsRollback
        {
            public function publish(Execution $execution): ExecutionResult
            {
                return new ExecutionResult('p', null, new \DateTimeImmutable());
            }

            public function supports(string $channelType): bool
            {
                return true;
            }

            public function ping(ChannelCredentials $credentials): PingResult
            {
                return new PingResult(reachable: true);
            }

            public function rollback(Execution $execution): bool
            {
                return true;
            }
        };

        $registry = new ChannelPublisherRegistry();
        $registry->register($rollablePublisher);

        $service = new RollbackService($registry);

        $execution = $this->makeCompletedExecution();

        $result = $service->rollback($this->campaign);

        $this->assertCount(1, $result['rolled_back']);
        $this->assertContains($execution->id, $result['rolled_back']);

        $asset = ContentAsset::withoutGlobalScopes()->find($execution->content_asset_id);
        $this->assertNotNull($asset);
        $this->assertEquals('archived', $asset->status);
    }

    public function test_failed_rollback_is_reported_in_failed_list(): void
    {
        $failingPublisher = new class() implements ChannelPublisher, SupportsRollback
        {
            public function publish(Execution $execution): ExecutionResult
            {
                return new ExecutionResult('p', null, new \DateTimeImmutable());
            }

            public function supports(string $channelType): bool
            {
                return true;
            }

            public function ping(ChannelCredentials $credentials): PingResult
            {
                return new PingResult(reachable: true);
            }

            public function rollback(Execution $execution): bool
            {
                throw new \RuntimeException('API call failed');
            }
        };

        $registry = new ChannelPublisherRegistry();
        $registry->register($failingPublisher);

        $service = new RollbackService($registry);

        $this->makeCompletedExecution();

        $result = $service->rollback($this->campaign);

        $this->assertCount(1, $result['failed']);
        $this->assertCount(0, $result['rolled_back']);
    }

    public function test_only_completed_executions_are_rolled_back(): void
    {
        $fakePublisher = new FakeChannelPublisher();
        $registry = new ChannelPublisherRegistry();
        $registry->register($fakePublisher);

        $service = new RollbackService($registry);

        // Create a failed execution — should not be included in rollback
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Body.',
            'status' => 'approved',
        ]);

        Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'failed',
            'idempotency_key' => Str::ulid()->toString(),
        ]);

        $result = $service->rollback($this->campaign);

        // failed execution is not included in rollback
        $this->assertCount(0, $result['unrollable']);
        $this->assertCount(0, $result['rolled_back']);
        $this->assertCount(0, $result['failed']);
    }

    public function test_empty_campaign_rollback_returns_empty_lists(): void
    {
        $fakePublisher = new FakeChannelPublisher();
        $registry = new ChannelPublisherRegistry();
        $registry->register($fakePublisher);

        $service = new RollbackService($registry);

        $result = $service->rollback($this->campaign);

        $this->assertCount(0, $result['rolled_back']);
        $this->assertCount(0, $result['unrollable']);
        $this->assertCount(0, $result['failed']);
    }
}
