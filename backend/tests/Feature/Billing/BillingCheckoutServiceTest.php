<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingCheckoutService;
use App\Billing\BillingProfileService;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Exceptions\BillingException;
use App\Billing\Testing\FakeBillingProvider;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    private BillingCheckoutService $service;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.price_id', 'price_atlas_test');

        $this->fake = new FakeBillingProvider();
        $this->app->instance(BillingProvider::class, $this->fake);
        $this->service = new BillingCheckoutService($this->fake, new BillingProfileService());

        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->user = User::factory()->create(['email' => 'owner@acme.test']);
    }

    public function test_it_creates_a_customer_links_it_and_returns_a_checkout_session(): void
    {
        $session = $this->service->startSubscriptionCheckout(
            $this->company,
            $this->user,
            'https://atlas.test/ok',
            'https://atlas.test/cancel',
        );

        $this->assertSame($this->fake->checkoutUrl, $session->url);

        $this->fake->assertCalled('ensureCustomer');
        $this->assertDatabaseHas('billing_profiles', [
            'company_id' => $this->company->id,
        ]);
        $this->assertNotNull($this->company->billingProfile()->first()->stripe_customer_id);

        $checkoutCall = collect($this->fake->calls)->firstWhere('method', 'createSubscriptionCheckout');
        /** @var CheckoutRequest $req */
        $req = $checkoutCall['args']['request'];
        $this->assertSame('price_atlas_test', $req->priceId);
        $this->assertSame($this->company->id, $req->clientReferenceId);
        $this->assertSame('https://atlas.test/ok', $req->successUrl);
    }

    public function test_it_reuses_an_already_linked_stripe_customer(): void
    {
        (new BillingProfileService())->linkCustomer($this->company, 'cus_existing');

        $this->service->startSubscriptionCheckout($this->company, $this->user, 'https://a/ok', 'https://a/cancel');

        $this->fake->assertNotCalled('ensureCustomer');
        $checkoutCall = collect($this->fake->calls)->firstWhere('method', 'createSubscriptionCheckout');
        $this->assertSame('cus_existing', $checkoutCall['args']['request']->customerId);
    }

    public function test_it_fails_when_no_price_is_configured(): void
    {
        config()->set('billing.price_id', null);

        $this->expectException(BillingException::class);

        $this->service->startSubscriptionCheckout($this->company, $this->user, 'https://a/ok', 'https://a/cancel');
    }
}
