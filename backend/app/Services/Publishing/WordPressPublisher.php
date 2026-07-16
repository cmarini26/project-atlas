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
use GuzzleHttp\Exception\ConnectException;
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

    /**
     * Verifies a WordPress site is genuinely reachable and the submitted
     * Application Password credential really authenticates against it,
     * before SettingsController::connectWordPress() ever persists
     * `status: 'active'`. Every failure mode returns a PingResult rather
     * than throwing, so a bad connect attempt always surfaces as a clean
     * validation error, never a 500.
     */
    public function ping(ChannelCredentials $credentials): PingResult
    {
        [$username, $appPassword] = $this->decode($credentials);

        $channel = Channel::withoutGlobalScopes()
            ->where('company_id', $credentials->company_id)
            ->where('type', 'blog')
            ->first();

        if ($channel === null) {
            return new PingResult(reachable: false, error: 'No WordPress site is configured for this company yet.');
        }

        /** @var array<string, mixed> $channelConfig */
        $channelConfig = $channel->config ?? [];
        $siteUrl = (string) ($channelConfig['site_url'] ?? '');

        if ($siteUrl === '') {
            return new PingResult(reachable: false, error: 'No site URL is configured.');
        }

        try {
            $response = $this->http->get(rtrim($siteUrl, '/').'/wp-json/wp/v2/users/me', [
                'auth' => [$username, $appPassword],
            ]);
        } catch (ConnectException $e) {
            // DNS failure, connection refused, timeout — a distinct Guzzle
            // exception branch from RequestException (ConnectException
            // extends TransferException directly, not RequestException),
            // so this needs its own catch or an unreachable host would
            // 500 the connect request instead of failing validation cleanly.
            return new PingResult(reachable: false, error: "Couldn't reach {$siteUrl}: {$e->getMessage()}");
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            return new PingResult(reachable: false, error: $body);
        }

        // A 2xx response alone doesn't prove this is really a WordPress
        // REST API — some hosts return 200 for any path (catch-all routing,
        // a misconfigured reverse proxy, a non-WordPress site entirely).
        // The real `/wp-json/wp/v2/users/me` endpoint always returns a JSON
        // object with at least an `id` field for the authenticated user.
        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded) || ! array_key_exists('id', $decoded)) {
            return new PingResult(
                reachable: false,
                error: 'The site responded, but not with a recognizable WordPress REST API user — check the site URL.',
            );
        }

        return new PingResult(reachable: true);
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
