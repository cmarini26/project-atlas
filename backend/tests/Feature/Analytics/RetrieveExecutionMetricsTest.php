<?php

namespace Tests\Feature\Analytics;

use App\Jobs\RetrieveExecutionMetrics;
use App\Models\ContentAsset;
use App\Models\Execution;
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
