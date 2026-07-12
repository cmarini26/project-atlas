<?php

namespace Tests\Feature\Publishing\Meta;

use App\Services\Publishing\Exceptions\AuthenticationException;
use App\Services\Publishing\MetaOAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class MetaOAuthServiceTest extends TestCase
{
    /** @param  list<Response>  $responses */
    private function makeService(array $responses, array &$history = []): MetaOAuthService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        return new MetaOAuthService($http, 'app-123', 'app-secret-xyz', 'https://atlas.test/app/settings/meta/callback');
    }

    public function test_authorization_url_contains_pkce_challenge_state_and_scopes(): void
    {
        $service = $this->makeService([]);

        $url = $service->buildAuthorizationUrl('random-state-abc', 'challenge-xyz');

        $this->assertStringContainsString('https://www.facebook.com/v19.0/dialog/oauth?', $url);
        $this->assertStringContainsString('client_id=app-123', $url);
        $this->assertStringContainsString('state=random-state-abc', $url);
        $this->assertStringContainsString('code_challenge=challenge-xyz', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('instagram_basic', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function test_exchange_code_for_token_returns_the_access_token(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode(['access_token' => 'short-lived-token-1', 'token_type' => 'bearer'])),
        ]);

        $token = $service->exchangeCodeForToken('auth-code', 'verifier-value');

        $this->assertSame('short-lived-token-1', $token);
    }

    public function test_exchange_code_for_token_sends_the_verifier_and_code(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200, [], json_encode(['access_token' => 'tok'])),
        ], $history);

        $service->exchangeCodeForToken('auth-code-abc', 'verifier-xyz');

        $query = $history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('code_verifier=verifier-xyz', $query);
        $this->assertStringContainsString('code=auth-code-abc', $query);
        $this->assertStringContainsString('client_secret=app-secret-xyz', $query);
    }

    public function test_exchange_for_long_lived_token_uses_the_exchange_grant_type(): void
    {
        $history = [];
        $service = $this->makeService([
            new Response(200, [], json_encode(['access_token' => 'long-lived-token'])),
        ], $history);

        $token = $service->exchangeForLongLivedToken('short-lived-token');

        $this->assertSame('long-lived-token', $token);
        $query = $history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('grant_type=fb_exchange_token', $query);
        $this->assertStringContainsString('fb_exchange_token=short-lived-token', $query);
    }

    public function test_throws_when_no_access_token_is_returned(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode(['error' => ['message' => 'Invalid code']])),
        ]);

        $this->expectException(AuthenticationException::class);

        $service->exchangeCodeForToken('bad-code', 'verifier');
    }

    public function test_throws_on_a_request_failure(): void
    {
        $service = $this->makeService([
            new Response(400, [], json_encode(['error' => ['message' => 'Invalid verifier']])),
        ]);

        $this->expectException(AuthenticationException::class);

        $service->exchangeCodeForToken('code', 'bad-verifier');
    }

    public function test_fetch_pages_returns_normalized_page_list(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'page-1', 'name' => 'CBB Auctions', 'access_token' => 'page-token-1'],
            ]])),
        ]);

        $pages = $service->fetchPages('user-access-token');

        $this->assertCount(1, $pages);
        $this->assertSame('page-1', $pages[0]['id']);
        $this->assertSame('CBB Auctions', $pages[0]['name']);
        $this->assertSame('page-token-1', $pages[0]['access_token']);
    }

    public function test_fetch_pages_returns_empty_list_with_no_pages(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $this->assertSame([], $service->fetchPages('user-access-token'));
    }

    public function test_fetch_instagram_business_account_id_returns_the_linked_id(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode(['instagram_business_account' => ['id' => 'ig-123']])),
        ]);

        $id = $service->fetchInstagramBusinessAccountId('page-1', 'page-token');

        $this->assertSame('ig-123', $id);
    }

    public function test_fetch_instagram_business_account_id_returns_null_when_unlinked(): void
    {
        $service = $this->makeService([
            new Response(200, [], json_encode([])),
        ]);

        $this->assertNull($service->fetchInstagramBusinessAccountId('page-1', 'page-token'));
    }
}
