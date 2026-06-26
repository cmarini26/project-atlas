<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Contracts\ChannelRenderer;

class GenericRenderer implements ChannelRenderer
{
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload
    {
        return new PlatformPayload(
            channelType: $channel->type,
            data: [
                'type' => $asset->type,
                'body' => $asset->body,
                'title' => $asset->title,
                'media' => $asset->media ?? [],
                'metadata' => $asset->metadata ?? [],
            ]
        );
    }

    public function supports(string $channelType): bool
    {
        return true;
    }
}
