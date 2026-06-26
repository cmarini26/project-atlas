<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Exceptions\PublishingException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

class FakeEmailProvider implements EmailProvider
{
    /** @var list<string|PublishingException> */
    private array $queue = [];

    /** @var list<array{payload: EmailPayload, credentials: ChannelCredentials}> */
    private array $sent = [];

    public function queueMessageId(string $messageId): static
    {
        $this->queue[] = $messageId;

        return $this;
    }

    public function queueFailure(PublishingException $exception): static
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function send(EmailPayload $payload, ChannelCredentials $credentials): string
    {
        if (empty($this->queue)) {
            $messageId = 'fake-email-'.Str::ulid()->toString();
            $this->sent[] = ['payload' => $payload, 'credentials' => $credentials];

            return $messageId;
        }

        $next = array_shift($this->queue);

        if ($next instanceof PublishingException) {
            throw $next;
        }

        $this->sent[] = ['payload' => $payload, 'credentials' => $credentials];

        return $next;
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        return new PingResult(reachable: true, error: null);
    }

    public function supports(string $providerType): bool
    {
        return true;
    }

    public function assertSent(int $count = 1): void
    {
        Assert::assertCount(
            $count,
            $this->sent,
            "Expected {$count} email(s) sent, but {$this->sentCount()} were recorded."
        );
    }

    public function assertNotSent(): void
    {
        Assert::assertEmpty(
            $this->sent,
            "Expected no emails sent, but {$this->sentCount()} were recorded."
        );
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }

    /** @return list<array{payload: EmailPayload, credentials: ChannelCredentials}> */
    public function sentItems(): array
    {
        return $this->sent;
    }
}
