<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Domain\Publishing\ValueObjects\SmsPayload;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\SmsProviderRegistry;
use DateTimeImmutable;

class SmsPublisher implements ChannelPublisher
{
    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly SmsProviderRegistry $smsProviders,
    ) {}

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);
        $credentials = $this->credentialsRepository->for($execution->company_id, 'sms');
        $payload = $this->renderers->for($channel->type)->render($asset, $channel);
        $smsPayload = SmsPayload::fromPlatformPayload($payload);

        /** @var array<string, mixed> $channelConfig */
        $channelConfig = $channel->config ?? [];
        $fromNumber = trim((string) ($channelConfig['from_number'] ?? ''));
        $toNumber = trim((string) ($channelConfig['to_number'] ?? $smsPayload->toNumber ?? ''));

        if ($fromNumber === '') {
            throw new PublishingException('Cannot send SMS: the connected channel has no sending number configured.', retryable: false);
        }

        if ($toNumber === '') {
            throw new PublishingException('Cannot send SMS: set a destination number in Settings before publishing.', retryable: false);
        }

        $providerType = $credentials->provider_type ?? 'twilio';
        $provider = $this->smsProviders->for($providerType);
        $messageId = $provider->send($fromNumber, $toNumber, $smsPayload->body, $credentials);

        return new ExecutionResult(
            platformId: $messageId,
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: [
                'publisher' => 'sms',
                'provider' => $providerType,
                'to_number' => $toNumber,
                'from_number' => $fromNumber,
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'sms';
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        $providerType = $credentials->provider_type ?? 'twilio';

        return $this->smsProviders->for($providerType)->ping($credentials);
    }
}
