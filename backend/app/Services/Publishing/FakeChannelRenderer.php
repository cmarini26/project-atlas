<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Contracts\ChannelRenderer;
use PHPUnit\Framework\Assert;

class FakeChannelRenderer implements ChannelRenderer
{
    /** @var list<array{asset: ContentAsset, channel: Channel}> */
    private array $rendered = [];

    public function render(ContentAsset $asset, Channel $channel): PlatformPayload
    {
        $this->rendered[] = ['asset' => $asset, 'channel' => $channel];

        return new PlatformPayload(
            channelType: $channel->type,
            data: [
                'type' => $asset->type,
                'body' => $asset->body,
            ]
        );
    }

    public function supports(string $channelType): bool
    {
        return true;
    }

    public function assertRendered(int $count = 1): void
    {
        Assert::assertCount(
            $count,
            $this->rendered,
            "Expected {$count} render(s), but {$this->renderedCount()} were recorded."
        );
    }

    public function assertNotRendered(): void
    {
        Assert::assertEmpty(
            $this->rendered,
            "Expected no renders, but {$this->renderedCount()} were recorded."
        );
    }

    public function renderedCount(): int
    {
        return count($this->rendered);
    }

    /** @return list<array{asset: ContentAsset, channel: Channel}> */
    public function renderedItems(): array
    {
        return $this->rendered;
    }
}
