<?php

namespace Tests\Unit\Billing;

use App\Billing\Exceptions\WebhookVerificationException;
use App\Billing\Providers\StripeBillingProvider;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Webhook signature verification is testable without any network — Stripe's
 * scheme is HMAC-SHA256 over "timestamp.payload". Full request/response
 * mocking for customers/checkout/subscriptions lands in CM-79.
 */
class StripeBillingProviderWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function provider(?string $webhookSecret = self::SECRET): StripeBillingProvider
    {
        return new StripeBillingProvider(
            client: new StripeClient('sk_test_x'),
            webhookSecret: $webhookSecret,
        );
    }

    private function signedHeader(string $payload, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public function test_it_returns_a_typed_event_for_a_correctly_signed_payload(): void
    {
        $payload = json_encode([
            'id' => 'evt_test',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'data' => ['object' => ['id' => 'cs_test', 'client_reference_id' => 'company-1']],
        ], JSON_THROW_ON_ERROR);

        $event = $this->provider()->parseWebhookEvent($payload, $this->signedHeader($payload));

        $this->assertSame('evt_test', $event->id);
        $this->assertSame('checkout.session.completed', $event->type);
        $this->assertSame('company-1', $event->object['client_reference_id']);
        $this->assertFalse($event->livemode);
    }

    public function test_a_bad_signature_throws_webhook_verification_exception(): void
    {
        $payload = '{"id":"evt_x","type":"ping","data":{"object":{}}}';

        $this->expectException(WebhookVerificationException::class);

        $this->provider()->parseWebhookEvent($payload, $this->signedHeader($payload, 'whsec_wrong'));
    }

    public function test_a_missing_webhook_secret_throws_before_verifying(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('STRIPE_WEBHOOK_SECRET is not configured.');

        $this->provider(webhookSecret: '')->parseWebhookEvent('{}', 't=1,v1=x');
    }

    public function test_malformed_json_with_a_valid_signature_throws_webhook_verification_exception(): void
    {
        $payload = 'not json at all';

        $this->expectException(WebhookVerificationException::class);

        $this->provider()->parseWebhookEvent($payload, $this->signedHeader($payload));
    }
}
