<?php

namespace App\Services\Publishing;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a featured image to a WordPress site's media library, kept
 * separate from WordPressPublisher as its own single-responsibility,
 * independently testable service. A failed upload is non-fatal — the post
 * still gets created without a featured image — so this never throws.
 */
class WordPressMediaUploader
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function uploadFeaturedImage(string $siteUrl, string $imageUrl, string $username, string $appPassword): ?int
    {
        try {
            $imageResponse = $this->http->get($imageUrl);
            $bytes = (string) $imageResponse->getBody();
            $contentType = $imageResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';
            $filename = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image');

            $response = $this->http->post(rtrim($siteUrl, '/').'/wp-json/wp/v2/media', [
                'auth' => [$username, $appPassword],
                'headers' => [
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                    'Content-Type' => $contentType,
                ],
                'body' => $bytes,
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];
            $id = $decoded['id'] ?? null;

            return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
        } catch (GuzzleException $e) {
            Log::warning('WordPressMediaUploader: featured image upload failed, publishing without one.', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
