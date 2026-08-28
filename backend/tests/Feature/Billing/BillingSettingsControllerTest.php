<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingProfileService;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\Testing\FakeBillingProvider;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.price_id', 'price_atlas_test');
        $this->fake = new FakeBillingProvider();
        $this->app->instance(BillingProvider::class, $this->fake);
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/app/settings/billing')->assertRedirect('/login');
    }

    public function test_index_shows_an_unsubscribed_state_with_checkout_available(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Settings/Billing')
                ->where('billing.has_subscription', false)
                ->where('billing.grants_access', false)
                ->where('checkout_available', true)
                ->where('can_manage', true)
            );
    }

    public function test_index_reflects_an_active_subscription(): void
    {
        [$user, $company] = $this->userWithCompany();
        (new BillingProfileService())->applySubscriptionSnapshot($company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'active', 'price_atlas_test', CarbonImmutable::parse('2027-06-01T00:00:00Z'), false,
        ));

        $this->actingAs($user)
            ->get('/app/settings/billing')
            ->assertInertia(fn ($page) => $page
                ->where('billing.status', 'active')
                ->where('billing.has_subscription', true)
                ->where('billing.grants_access', true)
            );
    }

    public function test_checkout_result_query_is_passed_through(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings/billing?checkout=success')
            ->assertInertia(fn ($page) => $page->where('checkout_result', 'success'));
    }

    public function test_portal_is_owner_admin_only(): void
    {
        [$user] = $this->userWithCompany('member');

        $this->actingAs($user)->post('/app/settings/billing/portal')->assertForbidden();
    }

    public function test_portal_errors_when_the_company_has_no_stripe_customer(): void
    {
        [$user] = $this->userWithCompany('owner');

        $this->actingAs($user)
            ->from('/app/settings/billing')
            ->post('/app/settings/billing/portal')
            ->assertRedirect('/app/settings/billing')
            ->assertSessionHas('error');
    }

    public function test_portal_redirects_to_the_stripe_portal_when_a_customer_exists(): void
    {
        [$user, $company] = $this->userWithCompany('owner');
        (new BillingProfileService())->linkCustomer($company, 'cus_known');
        $this->fake->portalUrl = 'https://billing.stripe.test/portal/session_123';

        $this->actingAs($user)
            ->post('/app/settings/billing/portal')
            ->assertRedirect('https://billing.stripe.test/portal/session_123');

        $this->fake->assertCalled('createBillingPortalSession');
    }
}
