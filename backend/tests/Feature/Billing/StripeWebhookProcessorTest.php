<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingProfileService;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\StripeWebhookProcessor;
use App\Billing\Testing\FakeBillingProvider;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookProcessorTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    private StripeWebhookProcessor $processor;

    private BillingProfileService $profiles;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeBillingProvider();
        $this->app->instance(BillingProvider::class, $this->fake);
        $this->profiles = new BillingProfileService();
        $this->processor = new StripeWebhookProcessor($this->fake, $this->profiles);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    private function event(string $type, array $object): StripeWebhookEvent
    {
        return new StripeWebhookEvent('evt_'.uniqid(), $type, $object, [], false);
    }

    public function test_checkout_completed_links_the_customer_and_mirrors_the_subscription(): void
    {
        $this->fake->seedSubscription(new SubscriptionSnapshot('sub_1', 'cus_1', 'active', 'price_1', null, false));

        $this->processor->process($this->event('checkout.session.completed', [
            'client_reference_id' => $this->company->id,
            'customer' => 'cus_1',
            'subscription' => 'sub_1',
        ]));

        $profile = $this->profiles->find($this->company);
        $this->assertSame('cus_1', $profile->stripe_customer_id);
        $this->assertSame('sub_1', $profile->stripe_subscription_id);
        $this->assertTrue($profile->grantsAccess());
    }

    public function test_subscription_updated_mirrors_status_finding_the_company_by_metadata(): void
    {
        $this->processor->process($this->event('customer.subscription.updated', [
            'id' => 'sub_9',
            'customer' => 'cus_9',
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'metadata' => ['atlas_company_id' => $this->company->id],
        ]));

        $profile = $this->profiles->find($this->company);
        $this->assertSame('canceled', $profile->subscription_status);
        $this->assertFalse($profile->grantsAccess());
    }

    public function test_subscription_event_finds_the_company_by_stripe_customer_id_when_no_metadata(): void
    {
        $this->profiles->linkCustomer($this->company, 'cus_known');

        $this->processor->process($this->event('customer.subscription.updated', [
            'id' => 'sub_x',
            'customer' => 'cus_known',
            'status' => 'past_due',
        ]));

        $this->assertSame('past_due', $this->profiles->find($this->company)->subscription_status);
    }

    public function test_an_event_for_an_unknown_company_is_a_no_op(): void
    {
        $this->processor->process($this->event('customer.subscription.updated', [
            'id' => 'sub_z', 'customer' => 'cus_nobody', 'status' => 'active',
        ]));

        $this->assertDatabaseCount('billing_profiles', 0);
    }

    public function test_an_unhandled_event_type_does_nothing(): void
    {
        $this->processor->process($this->event('invoice.paid', ['id' => 'in_1']));

        $this->assertDatabaseCount('billing_profiles', 0);
    }
}
