<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingProfileService;
use App\Billing\Data\SubscriptionSnapshot;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequireBillingAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => 'owner']);

        Route::middleware(['web', 'auth', 'company', 'billing'])
            ->post('/__billing_gate_probe', fn () => response('ok'));
    }

    public function test_it_passes_through_while_gating_is_disabled(): void
    {
        config()->set('billing.gate_enabled', false);

        $this->actingAs($this->user)->post('/__billing_gate_probe')->assertOk();
    }

    public function test_it_redirects_a_web_request_to_billing_when_access_is_denied(): void
    {
        config()->set('billing.gate_enabled', true);

        $this->actingAs($this->user)
            ->post('/__billing_gate_probe')
            ->assertRedirect(route('app.settings.billing'))
            ->assertSessionHas('error');
    }

    public function test_it_returns_402_for_a_json_request_when_access_is_denied(): void
    {
        config()->set('billing.gate_enabled', true);

        $this->actingAs($this->user)
            ->postJson('/__billing_gate_probe')
            ->assertStatus(402)
            ->assertJsonStructure(['error']);
    }

    public function test_it_allows_a_company_with_an_active_subscription(): void
    {
        config()->set('billing.gate_enabled', true);
        (new BillingProfileService())->applySubscriptionSnapshot($this->company, new SubscriptionSnapshot(
            'sub_1', 'cus_1', 'active', null, null, false,
        ));

        $this->actingAs($this->user)->post('/__billing_gate_probe')->assertOk();
    }

    public function test_it_allows_a_company_with_the_beta_override(): void
    {
        config()->set('billing.gate_enabled', true);
        (new BillingProfileService())->setBetaAccessOverride($this->company, true);

        $this->actingAs($this->user)->post('/__billing_gate_probe')->assertOk();
    }
}
