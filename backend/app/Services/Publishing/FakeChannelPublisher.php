<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Exceptions\PublishingException;
use DateTimeImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

class FakeChannelPublisher implements ChannelPublisher
{
    /** @var list<ExecutionResult|PublishingException> */
    private array $queue = [];

    /** @var list<Execution> */
    private array $published = [];

    public function queueResult(ExecutionResult $result): static
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueFailure(PublishingException $exception): static
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function publish(Execution $execution): ExecutionResult
    {
        if (empty($this->queue)) {
            $result = new ExecutionResult(
                platformId: 'fake-'.Str::ulid()->toString(),
                url: null,
                publishedAt: new DateTimeImmutable(),
                metadata: ['publisher' => 'fake'],
            );

            $this->published[] = $execution;

            return $result;
        }

        $next = array_shift($this->queue);

        if ($next instanceof PublishingException) {
            throw $next;
        }

        $this->published[] = $execution;

        return $next;
    }

    public function supports(string $channelType): bool
    {
        return true;
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        return new PingResult(reachable: true, error: null);
    }

    public function assertPublished(int $count = 1): void
    {
        Assert::assertCount(
            $count,
            $this->published,
            "Expected {$count} publication(s), but {$this->publishedCount()} were recorded."
        );
    }

    public function assertNotPublished(): void
    {
        Assert::assertEmpty(
            $this->published,
            "Expected no publications, but {$this->publishedCount()} were recorded."
        );
    }

    public function publishedCount(): int
    {
        return count($this->published);
    }

    /** @return list<Execution> */
    public function publishedExecutions(): array
    {
        return $this->published;
    }
}
