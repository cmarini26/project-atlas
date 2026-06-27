<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Webhooks\PostmarkWebhookHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PostmarkWebhookHandlerTest extends TestCase
{
    private function loadFixture(string $name): string
    {
        return (string) file_get_contents(
            base_path("tests/Fixtures/Analytics/{$name}.json")
        );
    }

    private function makeRequest(string $body, array $headers = []): Request
    {
        $request = Request::create('/api/analytics/webhooks/postmark', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }

    public function test_parses_open_event(): void
    {
        $handler = new PostmarkWebhookHandler();
        $body = $this->loadFixture('postmark-open');
        $request = $this->makeRequest($body);

        $events = $handler->parse($request);

        $this->assertCount(1, $events);
        $this->assertEquals('open', $events[0]->eventType);
        $this->assertEquals('fake-message-id-abc123', $events[0]->platformMessageId);
        $this->assertEquals('postmark', $events[0]->providerType);
    }

    public function test_parses_bounce_event(): void
    {
        $handler = new PostmarkWebhookHandler();
        $body = $this->loadFixture('postmark-bounce');
        $request = $this->makeRequest($body);

        $events = $handler->parse($request);

        $this->assertCount(1, $events);
        $this->assertEquals('bounce', $events[0]->eventType);
        $this->assertEquals('fake-message-id-bounce456', $events[0]->platformMessageId);
        $this->assertEquals('HardBounce', $events[0]->metadata['bounce_type']);
    }

    public function test_returns_empty_array_for_unknown_record_type(): void
    {
        $handler = new PostmarkWebhookHandler();
        $body = json_encode(['RecordType' => 'Unknown', 'MessageID' => 'msg-1']) ?: '{}';
        $request = $this->makeRequest($body);

        $events = $handler->parse($request);

        $this->assertEmpty($events);
    }

    public function test_returns_empty_array_when_message_id_missing(): void
    {
        $handler = new PostmarkWebhookHandler();
        $body = json_encode(['RecordType' => 'Open']) ?: '{}';
        $request = $this->makeRequest($body);

        $events = $handler->parse($request);

        $this->assertEmpty($events);
    }

    public function test_verify_passes_when_no_secret_configured(): void
    {
        config(['services.postmark.webhook_secret' => '']);

        $handler = new PostmarkWebhookHandler();
        $request = $this->makeRequest('{}');

        $handler->verify($request);

        $this->assertTrue(true);
    }

    public function test_verify_fails_with_invalid_hmac(): void
    {
        config(['services.postmark.webhook_secret' => 'my-secret']);

        $handler = new PostmarkWebhookHandler();
        $request = $this->makeRequest('{}', ['X-Postmark-Signature' => 'invalid-sig']);

        $this->expectException(HttpException::class);

        $handler->verify($request);
    }

    public function test_verify_passes_with_valid_hmac(): void
    {
        $secret = 'my-test-secret';
        config(['services.postmark.webhook_secret' => $secret]);

        $body = '{"RecordType":"Open"}';
        $validSig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $handler = new PostmarkWebhookHandler();
        $request = $this->makeRequest($body, ['X-Postmark-Signature' => $validSig]);

        $handler->verify($request);

        $this->assertTrue(true);
    }

    public function test_supports_postmark_provider_type(): void
    {
        $handler = new PostmarkWebhookHandler();

        $this->assertTrue($handler->supports('postmark'));
        $this->assertFalse($handler->supports('sendgrid'));
    }
}
