<?php

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Testing\FakeBillingProvider;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.price_id', 'price_atlas_test');
        $this->fake = new FakeBillingProvider();
        $this->app->instance(BillingProvider::class, $this->fake);
        $this->app->instance(FakeBillingProvider::class, $this->fake);
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    public function test_it_requires_authentication(): void
    {
        $this->post('/app/settings/billing/checkout')->assertRedirect('/login');
    }

    public function test_a_non_admin_member_cannot_start_checkout(): void
    {
        [$user] = $this->userWithCompany('member');

        $this->actingAs($user)->post('/app/settings/billing/checkout')->assertForbidden();
        $this->fake->assertNotCalled('createSubscriptionCheckout');
    }

    public function test_an_owner_is_redirected_to_the_stripe_checkout_url_and_the_customer_is_linked(): void
    {
        [$user, $company] = $this->userWithCompany('owner');
        $this->fake->checkoutUrl = 'https://checkout.stripe.test/session/cs_owner';

        $this->actingAs($user)
            ->post('/app/settings/billing/checkout')
            ->assertRedirect('https://checkout.stripe.test/session/cs_owner');

        $this->assertNotNull($company->billingProfile()->first()->stripe_customer_id);
        $this->fake->assertCalled('createSubscriptionCheckout');
    }

    public function test_a_missing_price_configuration_flashes_an_error_and_does_not_redirect_to_stripe(): void
    {
        config()->set('billing.price_id', null);
        [$user] = $this->userWithCompany('admin');

        $this->actingAs($user)
            ->from('/app/settings/billing')
            ->post('/app/settings/billing/checkout')
            ->assertRedirect('/app/settings/billing')
            ->assertSessionHas('error');

        $this->fake->assertNotCalled('createSubscriptionCheckout');
    }
}
