<?php

namespace Tests\Feature\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PlatformPayload;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\Publishing\Email\LogEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class LogEmailProviderTest extends TestCase
{
    use RefreshDatabase;

    private LogEmailProvider $provider;

    private ChannelCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new LogEmailProvider();

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->credentials = ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'email',
            'provider_type' => 'log',
            'credentials' => json_encode(['from_address' => 'test@example.com']),
            'status' => 'active',
        ]);
    }

    private function makePayload(string $subject = 'Test Subject'): EmailPayload
    {
        return EmailPayload::fromPlatformPayload(new PlatformPayload(
            channelType: 'email',
            data: [
                'subject' => $subject,
                'from_name' => 'Sender',
                'from_email' => 'sender@example.com',
                'body' => 'Email body here.',
                'preview_text' => 'Preview.',
            ],
        ));
    }

    public function test_send_returns_message_id_starting_with_log_email(): void
    {
        $messageId = $this->provider->send($this->makePayload(), $this->credentials);

        $this->assertStringStartsWith('log-email-', $messageId);
    }

    public function test_send_returns_unique_message_ids(): void
    {
        $id1 = $this->provider->send($this->makePayload(), $this->credentials);
        $id2 = $this->provider->send($this->makePayload(), $this->credentials);

        $this->assertNotEquals($id1, $id2);
    }

    public function test_send_writes_to_publishing_log(): void
    {
        Log::shouldReceive('channel')
            ->with('publishing')
            ->once()
            ->andReturn(\Mockery::mock(LoggerInterface::class, function ($mock) {
                $mock->shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
                    return str_contains($message, 'LogEmailProvider')
                        && isset($context['subject'])
                        && $context['subject'] === 'Test Subject';
                });
            }));

        $this->provider->send($this->makePayload('Test Subject'), $this->credentials);
    }

    public function test_ping_returns_reachable_true(): void
    {
        $result = $this->provider->ping($this->credentials);

        $this->assertTrue($result->reachable);
        $this->assertNull($result->error);
    }

    public function test_supports_log_provider_type(): void
    {
        $this->assertTrue($this->provider->supports('log'));
    }

    public function test_does_not_support_other_provider_types(): void
    {
        foreach (['postmark', 'mailgun', 'ses', 'sendgrid'] as $type) {
            $this->assertFalse($this->provider->supports($type), "LogEmailProvider should not support {$type}");
        }
    }
}
