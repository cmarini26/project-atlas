<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Analytics\ValueObjects\WebhookEvent;
use App\Jobs\ApplyLearnings;
use App\Jobs\CheckChannelHealth;
use App\Jobs\ExpireOpportunities;
use App\Jobs\ProcessAnalyticsWebhookEvent;
use App\Jobs\PruneRawMetrics;
use App\Jobs\PublishScheduledContent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 4 (scheduler + queue production
 * readiness) — see docs/plans/Critical-Production-Blockers.md and
 * docs/reviews/Production-Deployment-Audit.md. Confirms every scheduled
 * entry is registered with overlap protection, and that the four jobs the
 * audit flagged as missing retry/backoff configuration now have it.
 */
class ScheduledJobsProductionReadinessTest extends TestCase
{
    /** @return array<int, Event> */
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    private function eventFor(string $description): Event
    {
        $event = collect($this->events())->first(
            fn (Event $event): bool => ($event->description ?? '') === $description
        );

        $this->assertNotNull($event, "Expected a scheduled event described as [{$description}].");

        return $event;
    }

    private function commandEvent(string $commandFragment): Event
    {
        $event = collect($this->events())->first(
            fn (Event $event): bool => str_contains((string) ($event->command ?? ''), $commandFragment)
        );

        $this->assertNotNull($event, "Expected a scheduled event whose command contains [{$commandFragment}].");

        return $event;
    }

    // ── Schedule registration ────────────────────────────────────────────────

    public function test_all_six_scheduled_entries_are_registered(): void
    {
        $descriptions = collect($this->events())->map(fn (Event $event): string => (string) ($event->description ?? $event->command ?? ''));

        $this->assertTrue($descriptions->contains(fn (string $d): bool => str_contains($d, 'atlas:sync-due-integrations')));
        $this->assertTrue($descriptions->contains(ExpireOpportunities::class));
        $this->assertTrue($descriptions->contains(PublishScheduledContent::class));
        $this->assertTrue($descriptions->contains(CheckChannelHealth::class));
        $this->assertTrue($descriptions->contains(PruneRawMetrics::class));
        $this->assertTrue($descriptions->contains(ApplyLearnings::class));
    }

    // ── Overlap protection ───────────────────────────────────────────────────

    public function test_every_scheduled_entry_has_overlap_protection(): void
    {
        foreach ($this->events() as $event) {
            $description = (string) ($event->description ?? $event->command ?? 'unknown');

            $this->assertTrue($event->withoutOverlapping, "Expected [{$description}] to have withoutOverlapping() applied.");
        }
    }

    public function test_sync_due_integrations_runs_on_one_server(): void
    {
        $this->assertTrue($this->commandEvent('atlas:sync-due-integrations')->onOneServer);
    }

    public function test_expire_opportunities_runs_on_one_server(): void
    {
        $this->assertTrue($this->eventFor(ExpireOpportunities::class)->onOneServer);
    }

    public function test_publish_scheduled_content_runs_on_one_server(): void
    {
        $this->assertTrue($this->eventFor(PublishScheduledContent::class)->onOneServer);
    }

    public function test_check_channel_health_runs_on_one_server(): void
    {
        $this->assertTrue($this->eventFor(CheckChannelHealth::class)->onOneServer);
    }

    public function test_prune_raw_metrics_runs_on_one_server(): void
    {
        $this->assertTrue($this->eventFor(PruneRawMetrics::class)->onOneServer);
    }

    // ── Queue assignment ─────────────────────────────────────────────────────

    public function test_check_channel_health_is_queued_on_maintenance(): void
    {
        $this->assertSame('maintenance', (new CheckChannelHealth())->queue);
    }

    public function test_prune_raw_metrics_is_queued_on_maintenance(): void
    {
        $this->assertSame('maintenance', (new PruneRawMetrics())->queue);
    }

    public function test_publish_scheduled_content_is_queued_on_maintenance(): void
    {
        $this->assertSame('maintenance', (new PublishScheduledContent())->queue);
    }

    // ── Retry/backoff configuration (the audit's named gap) ─────────────────

    public function test_check_channel_health_has_retry_and_backoff_configured(): void
    {
        $job = new CheckChannelHealth();

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff);
    }

    public function test_prune_raw_metrics_has_retry_and_backoff_configured(): void
    {
        $job = new PruneRawMetrics();

        $this->assertSame(3, $job->tries);
        $this->assertSame(300, $job->backoff);
    }

    public function test_publish_scheduled_content_has_retry_and_backoff_configured(): void
    {
        $job = new PublishScheduledContent();

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff);
    }

    public function test_process_analytics_webhook_event_has_retry_and_backoff_configured(): void
    {
        $event = new WebhookEvent(
            providerType: 'postmark',
            platformMessageId: 'msg-1',
            eventType: 'open',
            occurredAt: new \DateTimeImmutable(),
        );

        $job = new ProcessAnalyticsWebhookEvent($event);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->backoff);
        $this->assertSame('observations', $job->queue);
    }
}
