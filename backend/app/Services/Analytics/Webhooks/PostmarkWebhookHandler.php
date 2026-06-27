<?php

namespace App\Services\Analytics\Webhooks;

use App\Domain\Analytics\ValueObjects\WebhookEvent;
use App\Services\Analytics\Contracts\AnalyticsWebhookHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PostmarkWebhookHandler implements AnalyticsWebhookHandler
{
    private const EVENT_MAP = [
        'Delivery' => 'delivery',
        'Open' => 'open',
        'Click' => 'click',
        'Bounce' => 'bounce',
        'SpamComplaint' => 'complaint',
    ];

    public function verify(Request $request): void
    {
        $secret = config('services.postmark.webhook_secret', '');

        if (empty($secret)) {
            return;
        }

        $signature = $request->header('X-Postmark-Signature', '');

        $expected = base64_encode(
            hash_hmac('sha256', $request->getContent(), $secret, true)
        );

        if (! hash_equals($expected, (string) $signature)) {
            throw new HttpException(401, 'Invalid Postmark webhook signature.');
        }
    }

    /**
     * @return list<WebhookEvent>
     */
    public function parse(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $recordType = (string) ($payload['RecordType'] ?? '');
        $eventType = self::EVENT_MAP[$recordType] ?? null;

        if ($eventType === null) {
            return [];
        }

        $messageId = (string) ($payload['MessageID'] ?? '');
        if ($messageId === '') {
            return [];
        }

        $occurredAt = $this->parseDate($payload);

        /** @var array<string, mixed> $metadata */
        $metadata = [];

        if ($eventType === 'bounce') {
            $metadata['bounce_type'] = (string) ($payload['BounceType'] ?? '');
            $metadata['description'] = (string) ($payload['Description'] ?? '');
        }

        if ($eventType === 'click') {
            $metadata['original_link'] = (string) ($payload['OriginalLink'] ?? '');
        }

        if ($eventType === 'open') {
            $metadata['client'] = (string) ($payload['Client']['Name'] ?? '');
            $metadata['platform'] = (string) ($payload['ReadSeconds'] ?? '');
        }

        return [
            new WebhookEvent(
                providerType: 'postmark',
                platformMessageId: $messageId,
                eventType: $eventType,
                occurredAt: $occurredAt,
                metadata: $metadata,
            ),
        ];
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'postmark';
    }

    /** @param array<string, mixed> $payload */
    private function parseDate(array $payload): \DateTimeImmutable
    {
        $dateString = (string) ($payload['DeliveredAt']
            ?? $payload['BouncedAt']
            ?? $payload['ReceivedAt']
            ?? '');

        if ($dateString !== '') {
            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $dateString);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return new \DateTimeImmutable();
    }
}
