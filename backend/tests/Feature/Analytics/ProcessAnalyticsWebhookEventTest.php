<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\ValueObjects\WebhookEvent;
use App\Jobs\ProcessAnalyticsWebhookEvent;
use App\Models\ExecutionMetric;

class ProcessAnalyticsWebhookEventTest extends AnalyticsTestCase
{
    private ExecutionMetric $metric;

    protected function setUp(): void
    {
        parent::setUp();

        $execution = $this->makeExecution('completed', ['platform_id' => 'platform-msg-001']);

        $this->metric = ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => 'platform-msg-001',
            'is_final' => false, 'metrics' => ['delivered' => 100],
        ]);
    }

    private function makeEvent(string $eventType, string $messageId = 'platform-msg-001'): WebhookEvent
    {
        return new WebhookEvent(
            providerType: 'postmark',
            platformMessageId: $messageId,
            eventType: $eventType,
            occurredAt: new \DateTimeImmutable(),
        );
    }

    public function test_merges_open_event_into_metric(): void
    {
        $event = $this->makeEvent('open');

        (new ProcessAnalyticsWebhookEvent($event))->handle();

        $this->metric->refresh();
        $this->assertEquals(1, $this->metric->metrics['webhook_opens']);
    }

    public function test_increments_counter_idempotently_on_duplicate_events(): void
    {
        $event = $this->makeEvent('open');

        (new ProcessAnalyticsWebhookEvent($event))->handle();
        (new ProcessAnalyticsWebhookEvent($event))->handle();

        $this->metric->refresh();
        $this->assertEquals(2, $this->metric->metrics['webhook_opens']);
    }

    public function test_different_event_types_tracked_independently(): void
    {
        (new ProcessAnalyticsWebhookEvent($this->makeEvent('open')))->handle();
        (new ProcessAnalyticsWebhookEvent($this->makeEvent('click')))->handle();

        $this->metric->refresh();
        $this->assertEquals(1, $this->metric->metrics['webhook_opens']);
        $this->assertEquals(1, $this->metric->metrics['webhook_clicks']);
    }

    public function test_no_ops_when_platform_id_not_found(): void
    {
        $event = $this->makeEvent('open', 'unknown-msg-id');

        (new ProcessAnalyticsWebhookEvent($event))->handle();

        $this->metric->refresh();
        $this->assertArrayNotHasKey('webhook_opens', $this->metric->metrics ?? []);
    }

    public function test_does_not_change_is_final_flag(): void
    {
        $event = $this->makeEvent('open');
        (new ProcessAnalyticsWebhookEvent($event))->handle();

        $this->metric->refresh();
        $this->assertFalse($this->metric->is_final);
    }
}
