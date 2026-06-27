<?php

namespace App\Jobs;

use App\Domain\Analytics\ValueObjects\WebhookEvent;
use App\Models\ExecutionMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAnalyticsWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly WebhookEvent $event)
    {
        $this->onQueue('observations');
    }

    public function handle(): void
    {
        $metric = ExecutionMetric::withoutGlobalScopes()
            ->where('platform_id', $this->event->platformMessageId)
            ->first();

        if ($metric === null) {
            return;
        }

        /** @var array<string, mixed> $currentMetrics */
        $currentMetrics = $metric->metrics ?? [];

        $counterKey = 'webhook_'.$this->event->eventType.'s';

        $currentMetrics[$counterKey] = ((int) ($currentMetrics[$counterKey] ?? 0)) + 1;

        $metric->metrics = $currentMetrics;
        $metric->save();
    }
}
