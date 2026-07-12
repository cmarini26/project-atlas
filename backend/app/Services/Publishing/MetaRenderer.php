<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Contracts\ChannelRenderer;
use App\Services\Publishing\Exceptions\MalformedPayloadException;

/**
 * Replaces GenericRenderer for instagram/facebook — registered ahead of it
 * in ChannelRendererRegistry. GenericRenderer already passes ContentAsset's
 * media array through unchanged; this renderer additionally enforces
 * Meta's caption limit and requires an image (Instagram has no text-only
 * post type).
 */
class MetaRenderer implements ChannelRenderer
{
    private const CAPTION_LIMIT = 2200;

    /**
     * @throws MalformedPayloadException
     */
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload
    {
        /** @var list<array<string, mixed>> $media */
        $media = $asset->media ?? [];
        $imageUrl = (string) ($media[0]['url'] ?? '');

        if ($imageUrl === '') {
            throw new MalformedPayloadException(
                'Meta asset is missing an image. Instagram and Facebook photo posts require at least one image in media.'
            );
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $asset->metadata ?? [];
        /** @var list<mixed> $hashtags */
        $hashtags = $metadata['hashtags'] ?? [];

        $caption = (string) ($asset->body ?? '');

        if ($hashtags !== []) {
            $hashtagLine = implode(' ', array_map(fn (mixed $tag): string => '#'.ltrim((string) $tag, '#'), $hashtags));
            $caption = trim("{$caption}\n\n{$hashtagLine}");
        }

        if (mb_strlen($caption) > self::CAPTION_LIMIT) {
            $caption = mb_substr($caption, 0, self::CAPTION_LIMIT);
        }

        return new PlatformPayload(
            channelType: $channel->type,
            data: [
                'caption' => $caption,
                'image_url' => $imageUrl,
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return in_array($channelType, ['instagram', 'facebook'], true);
    }
}
