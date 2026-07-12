<?php

namespace App\Services\Publishing;

use App\Services\Publishing\Exceptions\AuthenticationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Encapsulates the Meta Graph API OAuth mechanics (PKCE authorization code
 * exchange, long-lived token exchange, page listing) as a standalone,
 * Guzzle-injectable service — kept separate from MetaOAuthController so the
 * controller stays thin and this HTTP-calling logic is independently
 * testable, matching this codebase's constructor-injection convention
 * (AnthropicProvider, PostmarkEmailProvider, MetaAnalyticsProvider).
 */
class MetaOAuthService
{
    private const AUTH_BASE_URL = 'https://www.facebook.com/v19.0';

    private const GRAPH_BASE_URL = 'https://graph.facebook.com/v19.0';

    private const SCOPES = 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement';

    private Client $http;

    private string $appId;

    private string $appSecret;

    private string $redirectUri;

    public function __construct(?Client $http = null, ?string $appId = null, ?string $appSecret = null, ?string $redirectUri = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
        $this->appId = $appId ?? (string) config('services.meta.app_id', '');
        $this->appSecret = $appSecret ?? (string) config('services.meta.app_secret', '');
        $this->redirectUri = $redirectUri ?? (string) config('services.meta.redirect_uri', '');
    }

    public function buildAuthorizationUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => self::SCOPES,
            'response_type' => 'code',
        ]);

        return self::AUTH_BASE_URL."/dialog/oauth?{$query}";
    }

    /**
     * @throws AuthenticationException
     */
    public function exchangeCodeForToken(string $code, string $codeVerifier): string
    {
        $response = $this->get('/oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
            'code' => $code,
        ]);

        return $this->extractAccessToken($response);
    }

    /**
     * @throws AuthenticationException
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): string
    {
        $response = $this->get('/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        return $this->extractAccessToken($response);
    }

    /**
     * @return list<array{id: string, name: string, access_token: string}>
     *
     * @throws AuthenticationException
     */
    public function fetchPages(string $userAccessToken): array
    {
        $response = $this->get('/me/accounts', ['access_token' => $userAccessToken]);

        /** @var list<array{id?: mixed, name?: mixed, access_token?: mixed}> $data */
        $data = $response['data'] ?? [];

        return array_map(fn (array $page): array => [
            'id' => (string) ($page['id'] ?? ''),
            'name' => (string) ($page['name'] ?? ''),
            'access_token' => (string) ($page['access_token'] ?? ''),
        ], $data);
    }

    /**
     * A Facebook Page and its linked Instagram professional account are
     * separate Graph API IDs — publishing to Instagram requires this one,
     * not the Page ID. Returns null when the Page has no linked IG account
     * (a Page is not required to have one).
     *
     * @throws AuthenticationException
     */
    public function fetchInstagramBusinessAccountId(string $pageId, string $pageAccessToken): ?string
    {
        $response = $this->get("/{$pageId}", [
            'fields' => 'instagram_business_account',
            'access_token' => $pageAccessToken,
        ]);

        $id = $response['instagram_business_account']['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws AuthenticationException
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http->get(self::GRAPH_BASE_URL.$path, ['query' => $query]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return $decoded;
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            throw new AuthenticationException("Meta OAuth request failed: {$body}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws AuthenticationException
     */
    private function extractAccessToken(array $response): string
    {
        $token = $response['access_token'] ?? null;

        if ($token === null || $token === '') {
            throw new AuthenticationException('Meta did not return an access token.');
        }

        return (string) $token;
    }
}
