<?php

namespace App\Domain\Publishing\ValueObjects;

use App\Services\Publishing\Exceptions\MalformedPayloadException;

readonly class EmailPayload
{
    public function __construct(
        public string $subject,
        public string $fromName,
        public string $fromEmail,
        public string $body,
        public string $previewText,
    ) {}

    /**
     * @throws MalformedPayloadException
     */
    public static function fromPlatformPayload(PlatformPayload $payload): self
    {
        $subject = (string) ($payload->data['subject'] ?? '');

        if ($subject === '') {
            throw new MalformedPayloadException('Email payload is missing a subject line.');
        }

        return new self(
            subject: $subject,
            fromName: (string) ($payload->data['from_name'] ?? ''),
            fromEmail: (string) ($payload->data['from_email'] ?? ''),
            body: (string) ($payload->data['body'] ?? ''),
            previewText: (string) ($payload->data['preview_text'] ?? ''),
        );
    }
}
