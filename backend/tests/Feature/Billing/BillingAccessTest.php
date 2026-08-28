<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingAccess;
use App\Billing\BillingProfileService;
use App\Billing\Data\SubscriptionSnapshot;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAccessTest extends TestCase
{
    use RefreshDatabase;

    private BillingAccess $access;

    private BillingProfileService $profiles;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiles = new BillingProfileService();
        $this->access = new BillingAccess($this->profiles);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_everything_is_allowed_while_gating_is_disabled(): void
    {
        config()->set('billing.gate_enabled', false);

        $this->assertTrue($this->access->allows($this->company));
        $this->assertNull($this->access->deniedReason($this->company));
    }

    public function test_gating_on_denies_a_company_with_no_subscription(): void
    {
        config()->set('billing.gate_enabled', true);

        $this->assertFalse($this->access->allows($this->company));
        $this->assertNotNull($this->access->deniedReason($this->company));
    }

    public function test_gating_on_allows_an_active_subscription(): void
    {
        config()->set('billing.gate_enabled', true);
        $this->profiles->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'active', null, null, false,
        ));

        $this->assertTrue($this->access->allows($this->company));
    }

    public function test_the_beta_override_allows_access_regardless_of_stripe_state(): void
    {
        config()->set('billing.gate_enabled', true);
        $this->profiles->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'canceled', null, null, false,
        ));
        $this->profiles->setBetaAccessOverride($this->company, true);

        $this->assertTrue($this->access->allows($this->company));
    }
}
