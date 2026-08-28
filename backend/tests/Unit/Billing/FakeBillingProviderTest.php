<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\Exceptions\WebhookVerificationException;
use App\Billing\Testing\FakeBillingProvider;
use PHPUnit\Framework\TestCase;

class FakeBillingProviderTest extends TestCase
{
    private function request(string $companyId = 'company-1'): CheckoutRequest
    {
        return new CheckoutRequest(
            customerId: 'cus_fake',
            priceId: 'price_fake',
            successUrl: 'https://atlas.test/billing?ok=1',
            cancelUrl: 'https://atlas.test/billing?cancelled=1',
            clientReferenceId: $companyId,
        );
    }

    public function test_it_satisfies_the_interface(): void
    {
        $this->assertInstanceOf(BillingProvider::class, new FakeBillingProvider());
    }

    public function test_ensure_customer_is_idempotent_per_company_and_records_the_call(): void
    {
        $fake = new FakeBillingProvider();

        $first = $fake->ensureCustomer('company-1', 'a@b.test', 'Acme');
        $second = $fake->ensureCustomer('company-1', 'a@b.test', 'Acme');
        $other = $fake->ensureCustomer('company-2', 'c@d.test', 'Other');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $fake->assertCalled('ensureCustomer');
    }

    public function test_checkout_and_portal_return_the_configured_urls(): void
    {
        $fake = new FakeBillingProvider();
        $fake->checkoutUrl = 'https://checkout.example/x';
        $fake->portalUrl = 'https://portal.example/y';

        $this->assertSame('https://checkout.example/x', $fake->createSubscriptionCheckout($this->request())->url);
        $this->assertSame('https://portal.example/y', $fake->createBillingPortalSession('cus_fake', 'https://atlas.test')->url);
    }

    public function test_parse_webhook_decodes_the_payload_when_no_event_is_seeded(): void
    {
        $fake = new FakeBillingProvider();

        $event = $fake->parseWebhookEvent(json_encode([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1']],
        ], JSON_THROW_ON_ERROR), 't=1,v1=sig');

        $this->assertSame('evt_1', $event->id);
        $this->assertSame('checkout.session.completed', $event->type);
        $this->assertSame('cs_1', $event->object['id']);
    }

    public function test_a_seeded_event_is_returned_verbatim(): void
    {
        $seeded = new StripeWebhookEvent('evt_seed', 'customer.subscription.updated', ['status' => 'active'], [], false);
        $fake = (new FakeBillingProvider())->nextWebhookEvent($seeded);

        $this->assertSame($seeded, $fake->parseWebhookEvent('{}', 'sig'));
    }

    public function test_rejected_signature_throws(): void
    {
        $fake = (new FakeBillingProvider())->rejectWebhookSignature();

        $this->expectException(WebhookVerificationException::class);

        $fake->parseWebhookEvent('{}', 'sig');
    }

    public function test_fetch_subscription_returns_a_seeded_snapshot(): void
    {
        $snapshot = new SubscriptionSnapshot('sub_1', 'cus_1', 'past_due', 'price_1', null, true);
        $fake = (new FakeBillingProvider())->seedSubscription($snapshot);

        $this->assertSame($snapshot, $fake->fetchSubscription('sub_1'));
        $this->assertTrue($fake->fetchSubscription('sub_unknown')->grantsAccess());
    }
}
