<?php

namespace Tests\Feature\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Events\CampaignPublished;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionFailed;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Opportunity;
use App\Services\Publishing\ExecutionService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExecutionService $service;

    private Company $company;

    private Campaign $campaign;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ExecutionService::class);

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
            'description' => 'Test',
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
            'expected_impact' => ['summary' => '10% lift'],
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
            'body' => 'Email body.',
            'status' => 'approved',
            'scheduled_at' => $scheduledAt,
        ]);
    }

    private function makeExecution(ContentAsset $asset, string $status = 'queued'): Execution
    {
        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => $status,
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    // --- queueForCampaign ---

    public function test_queue_for_campaign_creates_executions_for_approved_assets(): void
    {
        $this->makeApprovedAsset();
        $this->makeApprovedAsset();

        $executions = $this->service->queueForCampaign($this->campaign);

        $this->assertCount(2, $executions);
        $this->assertDatabaseCount('executions', 2);
    }

    public function test_queue_for_campaign_transitions_assets_to_scheduled(): void
    {
        $asset = $this->makeApprovedAsset();

        $this->service->queueForCampaign($this->campaign);

        $asset->refresh();
        $this->assertEquals('scheduled', $asset->status);
    }

    public function test_queue_for_campaign_sets_scheduled_at_from_asset(): void
    {
        $future = now()->addDay()->toDateTimeString();
        $this->makeApprovedAsset($future);

        $executions = $this->service->queueForCampaign($this->campaign);

        $this->assertNotNull($executions[0]->scheduled_at);
    }

    public function test_queue_for_campaign_skips_non_approved_assets(): void
    {
        ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'body' => 'Draft body.',
            'status' => 'draft',
        ]);

        $executions = $this->service->queueForCampaign($this->campaign);

        $this->assertCount(0, $executions);
    }

    // --- markCompleted ---

    public function test_mark_completed_sets_status_to_completed(): void
    {
        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $result = new ExecutionResult('plat-123', null, new DateTimeImmutable());
        $this->service->markCompleted($execution, $result);

        $execution->refresh();
        $this->assertEquals('completed', $execution->status);
        $this->assertNotNull($execution->completed_at);
    }

    public function test_mark_completed_stores_result(): void
    {
        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $result = new ExecutionResult('plat-abc', 'https://example.com', new DateTimeImmutable(), ['key' => 'val']);
        $this->service->markCompleted($execution, $result);

        $execution->refresh();
        $this->assertEquals('plat-abc', $execution->result['platform_id']);
    }

    public function test_mark_completed_transitions_asset_to_published(): void
    {
        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $this->service->markCompleted($execution, new ExecutionResult('plat-1', null, new DateTimeImmutable()));

        $asset->refresh();
        $this->assertEquals('published', $asset->status);
        $this->assertNotNull($asset->published_at);
    }

    public function test_mark_completed_fires_execution_completed_event(): void
    {
        Event::fake([ExecutionCompleted::class, CampaignPublished::class]);

        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $this->service->markCompleted($execution, new ExecutionResult('plat-1', null, new DateTimeImmutable()));

        Event::assertDispatched(ExecutionCompleted::class);
    }

    // --- markFailed ---

    public function test_mark_failed_sets_status_to_failed(): void
    {
        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $this->service->markFailed($execution, 'Rate limit hit');

        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
        $this->assertEquals('Rate limit hit', $execution->last_error);
    }

    public function test_mark_failed_reverts_asset_to_approved(): void
    {
        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $this->service->markFailed($execution, 'Network error');

        $asset->refresh();
        $this->assertEquals('approved', $asset->status);
    }

    public function test_mark_failed_is_idempotent_when_already_failed(): void
    {
        $asset = $this->makeApprovedAsset();
        $execution = $this->makeExecution($asset, 'failed');
        $execution->update(['last_error' => 'Original error']);

        $this->service->markFailed($execution, 'Second call');

        $execution->refresh();
        $this->assertEquals('Original error', $execution->last_error);
    }

    public function test_mark_failed_fires_execution_failed_event(): void
    {
        Event::fake([ExecutionFailed::class, CampaignPublished::class]);

        $asset = $this->makeApprovedAsset();
        $asset->update(['status' => 'scheduled']);
        $execution = $this->makeExecution($asset);

        $this->service->markFailed($execution, 'error');

        Event::assertDispatched(ExecutionFailed::class);
    }

    // --- logAttempt ---

    public function test_log_attempt_creates_execution_attempt_record(): void
    {
        $asset = $this->makeApprovedAsset();
        $execution = $this->makeExecution($asset);

        $this->service->logAttempt($execution, 'completed', null, ['platform_id' => 'abc']);

        $this->assertDatabaseCount('execution_attempts', 1);

        $attempt = ExecutionAttempt::withoutGlobalScopes()->first();
        $this->assertNotNull($attempt);
        $this->assertEquals($execution->id, $attempt->execution_id);
        $this->assertEquals('completed', $attempt->status);
        $this->assertEquals(1, $attempt->attempt_number);
    }

    public function test_log_attempt_increments_attempts_counter(): void
    {
        $asset = $this->makeApprovedAsset();
        $execution = $this->makeExecution($asset);

        $this->service->logAttempt($execution, 'failed', 'error');
        $this->service->logAttempt($execution, 'failed', 'error 2');

        $execution->refresh();
        $this->assertEquals(2, $execution->attempts);
    }

    // --- checkCampaignCompletion ---

    public function test_campaign_becomes_published_when_all_executions_complete(): void
    {
        Event::fake([CampaignPublished::class]);

        $asset = $this->makeApprovedAsset();
        $execution = $this->makeExecution($asset);
        $execution->update(['status' => 'completed']);

        $this->service->checkCampaignCompletion($this->campaign->id);

        $this->campaign->refresh();
        $this->assertEquals('published', $this->campaign->status);
        Event::assertDispatched(CampaignPublished::class);
    }

    public function test_campaign_becomes_cancelled_when_all_executions_fail(): void
    {
        Event::fake([CampaignPublished::class]);

        $asset = $this->makeApprovedAsset();
        $execution = $this->makeExecution($asset);
        $execution->update(['status' => 'failed']);

        $this->service->checkCampaignCompletion($this->campaign->id);

        $this->campaign->refresh();
        $this->assertEquals('cancelled', $this->campaign->status);
        Event::assertNotDispatched(CampaignPublished::class);
    }

    public function test_campaign_not_settled_while_executions_are_pending(): void
    {
        $asset1 = $this->makeApprovedAsset();
        $asset2 = $this->makeApprovedAsset();
        $exec1 = $this->makeExecution($asset1);
        $exec2 = $this->makeExecution($asset2);

        $exec1->update(['status' => 'completed']);
        // exec2 remains queued

        $this->service->checkCampaignCompletion($this->campaign->id);

        $this->campaign->refresh();
        $this->assertEquals('approved', $this->campaign->status);
    }

    public function test_campaign_published_when_some_complete_some_fail(): void
    {
        Event::fake([CampaignPublished::class]);

        $asset1 = $this->makeApprovedAsset();
        $asset2 = $this->makeApprovedAsset();
        $exec1 = $this->makeExecution($asset1);
        $exec2 = $this->makeExecution($asset2);

        $exec1->update(['status' => 'completed']);
        $exec2->update(['status' => 'failed']);

        $this->service->checkCampaignCompletion($this->campaign->id);

        $this->campaign->refresh();
        $this->assertEquals('published', $this->campaign->status);
    }
}
