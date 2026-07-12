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

        /** @var array<string, mixed> $channelConfig */
        $channelConfig = $channel->config ?? [];

        return new PlatformPayload(
            channelType: 'email',
            data: [
                'subject' => $subject,
                'from_name' => (string) ($metadata['from_name'] ?? ''),
                'from_email' => (string) ($metadata['from_email'] ?? ''),
                'body' => (string) ($asset->body ?? ''),
                'preview_text' => (string) ($metadata['preview_text'] ?? ''),
                // Recipient comes from the channel's own config (e.g. the
                // company's configured notification/list address), not the
                // content asset — the same asset can be delivered through
                // more than one email channel with different recipients.
                'to_email' => (string) ($channelConfig['to_email'] ?? ''),
                'to_name' => (string) ($channelConfig['to_name'] ?? ''),
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'email';
    }
}
