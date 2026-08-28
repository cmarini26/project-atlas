<?php

namespace Tests\Unit\Billing;

use App\Billing\Data\CheckoutRequest;
use App\Billing\Exceptions\BillingException;
use App\Billing\Providers\StripeBillingProvider;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * HTTP-mocked coverage of the Stripe request/response mapping (CM-79).
 * Webhook signature verification is covered separately in
 * StripeBillingProviderWebhookTest.
 */
class StripeBillingProviderApiTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function provider(FakeStripeHttpClient $http): StripeBillingProvider
    {
        // stripe-php's StripeClient routes through ApiRequestor, whose HTTP
        // client is a global — swap it for the fake for the duration of the test.
        ApiRequestor::setHttpClient($http);

        return new StripeBillingProvider(
            client: new StripeClient(['api_key' => 'sk_test_x']),
            webhookSecret: 'whsec_x',
        );
    }

    public function test_ensure_customer_reuses_a_match_from_search_before_creating(): void
    {
        $http = (new FakeStripeHttpClient())
            ->forUrl('/v1/customers/search', [
                'object' => 'search_result',
                'url' => '/v1/customers/search',
                'has_more' => false,
                'data' => [['id' => 'cus_found', 'object' => 'customer']],
            ]);

        $id = $this->provider($http)->ensureCustomer('company-1', 'a@b.test', 'Acme');

        $this->assertSame('cus_found', $id);
        $this->assertCount(1, $http->requests, 'No create call when search matched.');
    }

    public function test_ensure_customer_creates_one_tagged_with_the_company_id_when_search_is_empty(): void
    {
        $http = (new FakeStripeHttpClient())
            ->forUrl('/v1/customers/search', [
                'object' => 'search_result',
                'url' => '/v1/customers/search',
                'has_more' => false,
                'data' => [],
            ])
            ->forUrl('/v1/customers', ['id' => 'cus_new', 'object' => 'customer']);

        $id = $this->provider($http)->ensureCustomer('company-42', 'a@b.test', 'Acme');

        $this->assertSame('cus_new', $id);
        $create = collect($http->requests)->first(fn ($r) => $r['method'] === 'post' && str_ends_with($r['url'], '/v1/customers'));
        $this->assertSame('company-42', $create['params']['metadata']['atlas_company_id']);
    }

    public function test_create_subscription_checkout_maps_the_request_and_returns_the_url(): void
    {
        $http = (new FakeStripeHttpClient())->queue([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ]);

        $session = $this->provider($http)->createSubscriptionCheckout(new CheckoutRequest(
            customerId: 'cus_1',
            priceId: 'price_1',
            successUrl: 'https://atlas.test/ok',
            cancelUrl: 'https://atlas.test/cancel',
            clientReferenceId: 'company-1',
            metadata: ['atlas_company_id' => 'company-1'],
        ));

        $this->assertSame('cs_test_123', $session->id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $session->url);

        $params = $http->lastRequest()['params'];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('cus_1', $params['customer']);
        $this->assertSame('price_1', $params['line_items'][0]['price']);
        $this->assertSame('company-1', $params['client_reference_id']);
    }

    public function test_create_billing_portal_session_returns_the_url(): void
    {
        $http = (new FakeStripeHttpClient())->queue(['url' => 'https://billing.stripe.com/p/session/live_123']);

        $portal = $this->provider($http)->createBillingPortalSession('cus_1', 'https://atlas.test/settings/billing');

        $this->assertSame('https://billing.stripe.com/p/session/live_123', $portal->url);
    }

    public function test_fetch_subscription_maps_to_a_snapshot(): void
    {
        $http = (new FakeStripeHttpClient())->queue([
            'id' => 'sub_1',
            'object' => 'subscription',
            'customer' => 'cus_1',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => 1_900_000_000,
            'items' => ['object' => 'list', 'data' => [['price' => ['id' => 'price_1']]]],
        ]);

        $snapshot = $this->provider($http)->fetchSubscription('sub_1');

        $this->assertSame('sub_1', $snapshot->id);
        $this->assertSame('active', $snapshot->status);
        $this->assertSame('price_1', $snapshot->priceId);
        $this->assertTrue($snapshot->grantsAccess());
    }

    public function test_a_stripe_api_error_is_wrapped_in_a_billing_exception(): void
    {
        $http = (new FakeStripeHttpClient())->queue([
            'error' => ['type' => 'invalid_request_error', 'message' => 'No such price: price_missing'],
        ], status: 400);

        $this->expectException(BillingException::class);

        $this->provider($http)->createSubscriptionCheckout(new CheckoutRequest(
            customerId: 'cus_1',
            priceId: 'price_missing',
            successUrl: 'https://a/ok',
            cancelUrl: 'https://a/cancel',
            clientReferenceId: 'company-1',
        ));
    }
}
