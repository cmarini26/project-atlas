<?php

namespace App\Domain\Publishing\ValueObjects;

use App\Services\Publishing\Exceptions\MalformedPayloadException;

readonly class SmsPayload
{
    public function __construct(
        public string $body,
        public ?string $toNumber = null,
    ) {}

    /**
     * @throws MalformedPayloadException
     */
    public static function fromPlatformPayload(PlatformPayload $payload): self
    {
        $body = trim((string) ($payload->data['body'] ?? ''));

        if ($body === '') {
            throw new MalformedPayloadException('SMS payload is missing a message body.');
        }

        return new self(
            body: $body,
            toNumber: isset($payload->data['to_number']) ? (string) $payload->data['to_number'] : null,
        );
    }
}
