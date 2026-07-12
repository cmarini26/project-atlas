<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class MetaChannelPublisher implements ChannelPublisher
{
    private const BASE_URL = 'https://graph.facebook.com/v19.0';

    private Client $http;

    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly MetaMediaUploader $uploader,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);

        $credentials = $this->credentialsRepository->for($execution->company_id, $channel->type);
        [$accessToken, $targetId] = $this->decode($credentials);

        $payload = $this->renderers->for($channel->type)->render($asset, $channel);
        $imageUrl = (string) $payload->data['image_url'];
        $caption = (string) $payload->data['caption'];

        if ($channel->type === 'instagram') {
            $creationId = $this->uploader->createInstagramContainer($targetId, $imageUrl, $caption, $accessToken);
            $postId = $this->uploader->publishInstagramContainer($targetId, $creationId, $accessToken);
        } else {
            $postId = $this->uploader->publishFacebookPhoto($targetId, $imageUrl, $caption, $accessToken);
        }

        return new ExecutionResult(
            platformId: $postId,
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: ['publisher' => 'meta', 'channel_type' => $channel->type],
        );
    }

    public function supports(string $channelType): bool
    {
        return in_array($channelType, ['instagram', 'facebook'], true);
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        [$accessToken] = $this->decode($credentials);

        try {
            $this->http->get(self::BASE_URL.'/me', ['query' => ['access_token' => $accessToken]]);

            return new PingResult(reachable: true);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            return new PingResult(reachable: false, error: $body);
        }
    }

    /** @return array{0: string, 1: string} */
    private function decode(ChannelCredentials $credentials): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $credentials->credentials, true) ?? [];

        return [(string) ($decoded['access_token'] ?? ''), (string) ($decoded['target_id'] ?? '')];
    }
}
