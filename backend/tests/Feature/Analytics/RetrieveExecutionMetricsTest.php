<?php

namespace Tests\Feature\Analytics;

use App\Enums\EmailRecipientSnapshotStatus;
use App\Jobs\RetrieveExecutionMetrics;
use App\Models\ContentAsset;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Models\ExecutionMetric;
use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\CampaignKpiService;
use App\Services\Analytics\FakeAnalyticsProvider;
use App\Services\Publishing\ChannelCredentialsRepository;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class RetrieveExecutionMetricsTest extends AnalyticsTestCase
{
    private FakeAnalyticsProvider $fakeProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCredentials('email', 'postmark');
        $this->fakeProvider = $this->app->make(FakeAnalyticsProvider::class);
    }

    private function handle(string $executionId): void
    {
        (new RetrieveExecutionMetrics($executionId))->handle(
            $this->app->make(ChannelCredentialsRepository::class),
            $this->app->make(AnalyticsProviderRegistry::class),
            $this->app->make(CampaignKpiService::class),
        );
    }

    public function test_creates_execution_metric_on_success(): void
    {
        Queue::fake();
        $this->fakeProvider->queueMetrics([
            'normalised_reach' => 500, 'normalised_engagement' => 25,
            'normalised_engagement_rate' => 0.05,
        ]);
        $this->fakeProvider->setWindowClosed(true);

        $execution = $this->makeExecution();
        $this->handle($execution->id);

        $this->assertDatabaseHas('execution_metrics', [
            'execution_id' => $execution->id, 'is_final' => true,
        ]);
    }

    public function test_appends_success_metric_retrieval_log(): void
    {
        Queue::fake();
        $this->fakeProvider->queueMetrics(['normalised_reach' => 100]);
        $this->fakeProvider->setWindowClosed(true);

        $execution = $this->makeExecution();
        $this->handle($execution->id);

        $this->assertDatabaseHas('metric_retrieval_logs', [
            'execution_id' => $execution->id, 'status' => 'success',
        ]);
    }

    public function test_re_dispatches_when_window_not_closed(): void
    {
        Queue::fake();
        $this->fakeProvider->queueMetrics(['normalised_reach' => 100]);
        $this->fakeProvider->setWindowClosed(false);

        $execution = $this->makeExecution();
        $this->handle($execution->id);

        Queue::assertPushed(RetrieveExecutionMetrics::class, function (RetrieveExecutionMetrics $job) use ($execution): bool {
            return $job->executionId === $execution->id;
        });
        $this->assertDatabaseHas('campaign_kpi_snapshots', [
            'campaign_id' => $execution->campaign_id,
            'snapshot_type' => 'interim',
        ]);
    }

    public function test_does_not_create_duplicate_execution_metric_on_repeat(): void
    {
        Queue::fake();
        $this->fakeProvider->queueMetrics(['normalised_reach' => 100]);
        $this->fakeProvider->setWindowClosed(true);

        $execution = $this->makeExecution();
        $this->handle($execution->id);

        $this->fakeProvider->queueMetrics(['normalised_reach' => 200]);
        $this->handle($execution->id);

        $this->assertDatabaseCount('execution_metrics', 1);
    }

    public function test_audience_execution_pulls_and_aggregates_recipient_message_ids(): void
    {
        Queue::fake();
        $this->fakeProvider->queueMetrics(
            ['delivered' => 1, 'open_rate' => 1.0, 'normalised_reach' => 1, 'normalised_engagement' => 1],
            ['delivered' => 1, 'open_rate' => 0.0, 'normalised_reach' => 1, 'normalised_engagement' => 0],
        );
        $this->fakeProvider->setWindowClosed(true);

        $execution = $this->makeExecution(result: ['platform_id' => 'audience:'.$this->campaign->id]);

        foreach ([['one@example.com', 'postmark-1'], ['two@example.com', 'postmark-2']] as [$email, $messageId]) {
            EmailRecipientSnapshot::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'campaign_id' => $this->campaign->id,
                'execution_id' => $execution->id,
                'email' => $email,
                'status' => EmailRecipientSnapshotStatus::Sent,
                'provider_message_id' => $messageId,
            ]);
        }

        $this->handle($execution->id);

        $this->fakeProvider->assertPulled(2);
        $this->assertSame(
            ['postmark-1', 'postmark-2'],
            array_column($this->fakeProvider->pulledItems(), 'platform_id'),
        );

        $metric = ExecutionMetric::withoutGlobalScopes()->where('execution_id', $execution->id)->sole();

        $this->assertSame(2, $metric->metrics['delivered']);
        $this->assertSame(2, $metric->metrics['normalised_reach']);
        $this->assertSame(1, $metric->metrics['normalised_engagement']);
        $this->assertSame(0.5, $metric->metrics['open_rate']);
        $this->assertSame(
            ['postmark-1', 'postmark-2'],
            array_keys($metric->raw['messages']),
        );
    }

    public function test_appends_failure_log_and_rethrows(): void
    {
        Queue::fake();
        $this->fakeProvider->queueFailure(new \RuntimeException('API error'));

        $execution = $this->makeExecution();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error');

        $this->handle($execution->id);
    }

    public function test_skips_execution_that_is_not_completed(): void
    {
        Queue::fake();

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id, 'type' => 'email', 'body' => 'Body.', 'status' => 'scheduled',
        ]);

        $execution = Execution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id, 'status' => 'queued',
            'idempotency_key' => Str::ulid()->toString(),
            'result' => ['platform_id' => 'msg-x'],
        ]);

        $this->handle($execution->id);

        $this->fakeProvider->assertNotPulled();
        $this->assertDatabaseCount('execution_metrics', 0);
    }
}
