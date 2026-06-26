<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Email\EmailProviderRegistry;
use DateTimeImmutable;

class EmailPublisher implements ChannelPublisher
{
    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly EmailProviderRegistry $emailProviders,
    ) {}

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);

        $credentials = $this->credentialsRepository->for($execution->company_id, 'email');

        $payload = $this->renderers->for($channel->type)->render($asset, $channel);
        $emailPayload = EmailPayload::fromPlatformPayload($payload);

        $providerType = $credentials->provider_type ?? 'log';
        $provider = $this->emailProviders->for($providerType);

        $messageId = $provider->send($emailPayload, $credentials);

        return new ExecutionResult(
            platformId: $messageId,
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: [
                'publisher' => 'email',
                'provider' => $providerType,
                'subject' => $emailPayload->subject,
            ],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'email';
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        $providerType = $credentials->provider_type ?? 'log';

        return $this->emailProviders->for($providerType)->ping($credentials);
    }
}
