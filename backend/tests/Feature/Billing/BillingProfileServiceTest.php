<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingProfileService;
use App\Billing\Data\SubscriptionSnapshot;
use App\Models\BillingProfile;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingProfileService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingProfileService();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_for_company_creates_exactly_one_profile(): void
    {
        $a = $this->service->forCompany($this->company);
        $b = $this->service->forCompany($this->company);

        $this->assertTrue($a->is($b));
        $this->assertDatabaseCount('billing_profiles', 1);
    }

    public function test_link_customer_is_idempotent(): void
    {
        $this->service->linkCustomer($this->company, 'cus_1');
        $profile = $this->service->linkCustomer($this->company, 'cus_1');

        $this->assertSame('cus_1', $profile->stripe_customer_id);
        $this->assertDatabaseCount('billing_profiles', 1);
    }

    public function test_apply_subscription_snapshot_mirrors_stripe_state_and_is_idempotent(): void
    {
        $snapshot = new SubscriptionSnapshot(
            id: 'sub_1',
            customerId: 'cus_1',
            status: 'active',
            priceId: 'price_1',
            currentPeriodEnd: CarbonImmutable::parse('2027-01-01T00:00:00Z'),
            cancelAtPeriodEnd: false,
        );

        $this->service->applySubscriptionSnapshot($this->company, $snapshot);
        $profile = $this->service->applySubscriptionSnapshot($this->company, $snapshot);

        $this->assertDatabaseCount('billing_profiles', 1);
        $this->assertSame('sub_1', $profile->stripe_subscription_id);
        $this->assertSame('active', $profile->subscription_status);
        $this->assertSame('cus_1', $profile->stripe_customer_id);
        $this->assertTrue($profile->grantsAccess());

        $cancelled = $this->service->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'canceled', 'price_1', null, true,
        ));

        $this->assertFalse($cancelled->fresh()->grantsAccess());
        $this->assertTrue($cancelled->cancel_at_period_end);
    }

    public function test_apply_snapshot_does_not_clobber_an_existing_customer_id(): void
    {
        $this->service->linkCustomer($this->company, 'cus_original');

        $profile = $this->service->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_different', 'active', null, null, false,
        ));

        $this->assertSame('cus_original', $profile->stripe_customer_id);
    }

    public function test_beta_override_grants_access_regardless_of_stripe_status(): void
    {
        $this->service->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'canceled', null, null, false,
        ));

        $profile = $this->service->setBetaAccessOverride($this->company, true);

        $this->assertTrue($profile->grantsAccess());

        BillingProfile::query()->update(['beta_access_override' => false]);
        $this->assertFalse($profile->fresh()->grantsAccess());
    }
}
