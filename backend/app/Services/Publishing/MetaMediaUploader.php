<?php

namespace App\Services\Publishing;

use App\Services\Publishing\Exceptions\ContentPolicyViolationException;
use App\Services\Publishing\Exceptions\PublishingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Media upload/publish calls, kept separate from MetaChannelPublisher as its
 * own single-responsibility, independently testable service. Instagram
 * requires a two-step container-then-publish flow; Facebook's photo
 * endpoint accepts the image URL and caption in one call.
 */
class MetaMediaUploader
{
    private const BASE_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Error codes/subcodes Meta uses for content-policy rejections. Not
     * exhaustive — there's no registered Meta App to observe real responses
     * against yet; this is a best-effort mapping to revisit once real
     * traffic surfaces the actual codes returned.
     */
    private const CONTENT_POLICY_CODES = [368, 1390008, 9004];

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    /**
     * @throws PublishingException
     */
    public function createInstagramContainer(string $igUserId, string $imageUrl, string $caption, string $accessToken): string
    {
        $response = $this->post("/{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ]);

        return $this->extractId($response);
    }

    /**
     * @throws PublishingException
     */
    public function publishInstagramContainer(string $igUserId, string $creationId, string $accessToken): string
    {
        $response = $this->post("/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $accessToken,
        ]);

        return $this->extractId($response);
    }

    /**
     * @throws PublishingException
     */
    public function publishFacebookPhoto(string $pageId, string $imageUrl, string $caption, string $accessToken): string
    {
        $response = $this->post("/{$pageId}/photos", [
            'url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ]);

        return $this->extractId($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PublishingException
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->http->post(self::BASE_URL.$path, ['form_params' => $body]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return $decoded;
        } catch (RequestException $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * @throws PublishingException
     */
    private function handleFailure(RequestException $e): never
    {
        $status = $e->getResponse()?->getStatusCode();
        $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true) ?? [];
        $code = $decoded['error']['code'] ?? null;
        $subcode = $decoded['error']['error_subcode'] ?? null;
        $message = (string) ($decoded['error']['message'] ?? $body);

        if (in_array($code, self::CONTENT_POLICY_CODES, true) || in_array($subcode, self::CONTENT_POLICY_CODES, true)) {
            throw new ContentPolicyViolationException($message);
        }

        throw new PublishingException(
            "Meta media request failed: {$message}",
            retryable: $status === null || $status >= 500 || $status === 429,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws PublishingException
     */
    private function extractId(array $response): string
    {
        $id = $response['id'] ?? null;

        if ($id === null || $id === '') {
            throw new PublishingException('Meta did not return a media/post ID.', retryable: false);
        }

        return (string) $id;
    }
}
