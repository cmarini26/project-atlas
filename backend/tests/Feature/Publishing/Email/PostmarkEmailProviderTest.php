<?php

namespace Tests\Feature\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Services\Publishing\Email\PostmarkEmailProvider;
use App\Services\Publishing\Exceptions\PublishingException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostmarkEmailProviderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
    }

    /** @param  list<Response>  $responses */
    private function makeProvider(array $responses): PostmarkEmailProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $http = new Client(['handler' => $stack]);

        return new PostmarkEmailProvider($http, retryDelaysMs: [1, 1]);
    }

    private function makeCredentials(string $token = 'test-server-token'): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'credentials' => $token,
            'status' => 'active',
        ]);
    }

    private function makePayload(array $overrides = []): EmailPayload
    {
        return new EmailPayload(
            subject: $overrides['subject'] ?? 'Ending soon: Amazing Fantasy #15',
            fromName: $overrides['fromName'] ?? 'CBB Auctions',
            fromEmail: $overrides['fromEmail'] ?? 'auctions@cbbauctions.com',
            body: $overrides['body'] ?? '<p>Bid before Sunday.</p>',
            previewText: $overrides['previewText'] ?? 'Bid before Sunday.',
            toEmail: array_key_exists('toEmail', $overrides) ? $overrides['toEmail'] : 'collector@example.com',
            toName: $overrides['toName'] ?? 'Jamie Collector',
        );
    }

    public function test_supports_only_postmark(): void
    {
        $provider = $this->makeProvider([]);

        $this->assertTrue($provider->supports('postmark'));
        $this->assertFalse($provider->supports('log'));
        $this->assertFalse($provider->supports('mailgun'));
    }

    public function test_send_returns_the_postmark_message_id(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode(['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'msg-123-abc'])),
        ]);

        $messageId = $provider->send($this->makePayload(), $this->makeCredentials());

        $this->assertSame('msg-123-abc', $messageId);
    }

    public function test_send_passes_the_server_token_header(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'msg-1'])),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $provider = new PostmarkEmailProvider($http);

        $provider->send($this->makePayload(), $this->makeCredentials('secret-token-xyz'));

        $this->assertSame('secret-token-xyz', $history[0]['request']->getHeaderLine('X-Postmark-Server-Token'));
    }

    public function test_send_throws_a_non_retryable_exception_when_the_channel_has_no_recipient(): void
    {
        $provider = $this->makeProvider([]);

        $this->expectException(PublishingException::class);

        try {
            $provider->send($this->makePayload(['toEmail' => null]), $this->makeCredentials());
        } catch (PublishingException $e) {
            $this->assertFalse($e->isRetryable());

            throw $e;
        }
    }

    public function test_send_throws_a_non_retryable_exception_on_a_postmark_error_code(): void
    {
        $provider = $this->makeProvider([
            new Response(422, [], json_encode(['ErrorCode' => 300, 'Message' => 'Invalid email request'])),
        ]);

        try {
            $provider->send($this->makePayload(), $this->makeCredentials());
            $this->fail('Expected a PublishingException.');
        } catch (PublishingException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_send_retries_on_429_and_succeeds(): void
    {
        $provider = $this->makeProvider([
            new Response(429, [], 'Too Many Requests'),
            new Response(200, [], json_encode(['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'msg-after-retry'])),
        ]);

        $messageId = $provider->send($this->makePayload(), $this->makeCredentials());

        $this->assertSame('msg-after-retry', $messageId);
    }

    public function test_send_marks_a_5xx_failure_as_retryable(): void
    {
        $provider = $this->makeProvider([
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
        ]);

        try {
            $provider->send($this->makePayload(), $this->makeCredentials());
            $this->fail('Expected a PublishingException.');
        } catch (PublishingException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_ping_succeeds_when_the_server_endpoint_is_reachable(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode(['Name' => 'CBB Auctions Server'])),
        ]);

        $result = $provider->ping($this->makeCredentials());

        $this->assertTrue($result->reachable);
        $this->assertNull($result->error);
    }

    public function test_ping_fails_when_the_token_is_invalid(): void
    {
        $provider = $this->makeProvider([
            new Response(401, [], json_encode(['ErrorCode' => 10, 'Message' => 'Invalid server token'])),
        ]);

        $result = $provider->ping($this->makeCredentials('bad-token'));

        $this->assertFalse($result->reachable);
        $this->assertNotNull($result->error);
    }
}
