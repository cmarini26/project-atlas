<?php

namespace App\Services\Publishing\Contracts;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Exceptions\MalformedPayloadException;

interface ChannelRenderer
{
    /**
     * Transform a ContentAsset into a platform-ready payload.
     * No API calls. No credentials. Pure data transformation.
     *
     * @throws MalformedPayloadException
     */
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload;

    /**
     * Returns true if this renderer handles the given channel type.
     */
    public function supports(string $channelType): bool;
}
