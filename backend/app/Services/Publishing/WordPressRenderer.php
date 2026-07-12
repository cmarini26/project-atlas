<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Contracts\ChannelRenderer;
use App\Services\Publishing\Exceptions\MalformedPayloadException;

class WordPressRenderer implements ChannelRenderer
{
    /**
     * @throws MalformedPayloadException
     */
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload
    {
        $title = (string) ($asset->title ?? '');

        if ($title === '') {
            throw new MalformedPayloadException('Blog asset is missing a title.');
        }

        /** @var array<int, array{url?: string}> $media */
        $media = $asset->media ?? [];
        $imageUrl = (string) ($media[0]['url'] ?? '');

        return new PlatformPayload(
            channelType: 'blog',
            data: [
                'title' => $title,
                // BlogContentPrompt generates plain text (system prompt says
                // "body is the full blog post in plain text") — WordPress's
                // content field renders HTML, so blank-line-separated
                // paragraphs need wrapping or the post loses all structure.
                'content' => $this->toHtmlParagraphs((string) ($asset->body ?? '')),
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'blog';
    }

    private function toHtmlParagraphs(string $body): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($body)) ?: [];

        return implode('', array_map(
            fn (string $p): string => '<p>'.nl2br(e(trim($p))).'</p>',
            array_filter($paragraphs, fn (string $p): bool => trim($p) !== ''),
        ));
    }
}
