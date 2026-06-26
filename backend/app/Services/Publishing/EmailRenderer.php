<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\Channel;
use App\Models\ContentAsset;
use App\Services\Publishing\Contracts\ChannelRenderer;
use App\Services\Publishing\Exceptions\MalformedPayloadException;

class EmailRenderer implements ChannelRenderer
{
    /**
     * @throws MalformedPayloadException
     */
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $asset->metadata ?? [];

        $subject = (string) ($metadata['subject_line'] ?? $asset->title ?? '');

        if ($subject === '') {
            throw new MalformedPayloadException(
                'Email asset is missing a subject line. Set metadata.subject_line or title.'
            );
        }

        return new PlatformPayload(
            channelType: 'email',
            data: [
                'subject' => $subject,
                'from_name' => (string) ($metadata['from_name'] ?? ''),
                'from_email' => (string) ($metadata['from_email'] ?? ''),
                'body' => (string) ($asset->body ?? ''),
                'preview_text' => (string) ($metadata['preview_text'] ?? ''),
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'email';
    }
}
