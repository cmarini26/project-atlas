<?php

namespace App\Services\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Analytics\Contracts\AnalyticsProvider;
use PHPUnit\Framework\Assert;

class FakeAnalyticsProvider implements AnalyticsProvider
{
    /** @var list<array<string, mixed>|\Throwable> */
    private array $queue = [];

    /** @var list<array{platform_id: string, credentials: ChannelCredentials}> */
    private array $pulled = [];

    private bool $windowClosed = true;

    public function queueMetrics(mixed ...$metricSets): static
    {
        foreach ($metricSets as $metrics) {
            $this->queue[] = $metrics;
        }

        return $this;
    }

    public function queueFailure(\Throwable $e): static
    {
        $this->queue[] = $e;

        return $this;
    }

    public function setWindowClosed(bool $closed): static
    {
        $this->windowClosed = $closed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function pull(string $platformId, ChannelCredentials $credentials): array
    {
        $this->pulled[] = ['platform_id' => $platformId, 'credentials' => $credentials];

        if (empty($this->queue)) {
            return ['fake' => true, 'platform_id' => $platformId];
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        return $raw;
    }

    public function isWindowClosed(Execution $execution): bool
    {
        return $this->windowClosed;
    }

    public function pollingDelayHours(): int
    {
        return 0;
    }

    public function repollingIntervalHours(): int
    {
        return 0;
    }

    public function supports(string $providerType): bool
    {
        return true;
    }

    public function assertPulled(int $count = 1): void
    {
        Assert::assertCount(
            $count,
            $this->pulled,
            "Expected {$count} metric pull(s), but {$this->pulledCount()} were recorded."
        );
    }

    public function assertNotPulled(): void
    {
        Assert::assertEmpty(
            $this->pulled,
            "Expected no metric pulls, but {$this->pulledCount()} were recorded."
        );
    }

    public function pulledCount(): int
    {
        return count($this->pulled);
    }

    /** @return list<array{platform_id: string, credentials: ChannelCredentials}> */
    public function pulledItems(): array
    {
        return $this->pulled;
    }
}
