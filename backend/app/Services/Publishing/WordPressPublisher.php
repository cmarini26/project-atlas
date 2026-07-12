<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use App\Services\Publishing\Exceptions\PublishingException;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class WordPressPublisher implements ChannelPublisher
{
    private Client $http;

    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly WordPressMediaUploader $uploader,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);

        $credentials = $this->credentialsRepository->for($execution->company_id, $channel->type);
        [$username, $appPassword] = $this->decode($credentials);
        /** @var array<string, mixed> $channelConfig */
        $channelConfig = $channel->config ?? [];
        $siteUrl = (string) ($channelConfig['site_url'] ?? '');

        $payload = $this->renderers->for($channel->type)->render($asset, $channel);

        $body = [
            'title' => $payload->data['title'],
            'content' => $payload->data['content'],
            'status' => 'publish',
        ];

        $imageUrl = $payload->data['image_url'] ?? null;

        if ($imageUrl !== null) {
            $mediaId = $this->uploader->uploadFeaturedImage($siteUrl, $imageUrl, $username, $appPassword);

            if ($mediaId !== null) {
                $body['featured_media'] = $mediaId;
            }
        }

        $response = $this->post($siteUrl, '/wp-json/wp/v2/posts', $username, $appPassword, $body);

        return new ExecutionResult(
            platformId: (string) ($response['id'] ?? ''),
            url: isset($response['link']) ? (string) $response['link'] : null,
            publishedAt: new DateTimeImmutable(),
            metadata: ['publisher' => 'wordpress', 'channel_type' => $channel->type],
        );
    }

    public function supports(string $channelType): bool
    {
        return $channelType === 'blog';
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        [$username, $appPassword] = $this->decode($credentials);
        $siteUrl = '';

        try {
            $channel = Channel::withoutGlobalScopes()
                ->where('company_id', $credentials->company_id)
                ->where('type', 'blog')
                ->firstOrFail();
            /** @var array<string, mixed> $channelConfig */
            $channelConfig = $channel->config ?? [];
            $siteUrl = (string) ($channelConfig['site_url'] ?? '');

            $this->http->get(rtrim($siteUrl, '/').'/wp-json/wp/v2/users/me', [
                'auth' => [$username, $appPassword],
            ]);

            return new PingResult(reachable: true);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            return new PingResult(reachable: false, error: $body);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PublishingException
     */
    private function post(string $siteUrl, string $path, string $username, string $appPassword, array $body): array
    {
        try {
            $response = $this->http->post(rtrim($siteUrl, '/').$path, [
                'auth' => [$username, $appPassword],
                'json' => $body,
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return $decoded;
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $message = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            throw new PublishingException(
                "WordPress post request failed: {$message}",
                retryable: $status === null || $status >= 500 || $status === 429,
            );
        }
    }

    /** @return array{0: string, 1: string} */
    private function decode(ChannelCredentials $credentials): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $credentials->credentials, true) ?? [];

        return [(string) ($decoded['username'] ?? ''), (string) ($decoded['app_password'] ?? '')];
    }
}
