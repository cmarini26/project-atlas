<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Integration;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_dashboard_redirects_to_onboarding_when_no_integration(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->get('/app')
            ->assertRedirect(route('onboarding'));
    }

    public function test_dashboard_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Dashboard'));
    }

    public function test_dashboard_includes_twin_status_when_no_twin(): void
    {
        [$user] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Dashboard')
                ->where('health.twin_status', 'initializing')
            );
    }

    public function test_dashboard_shows_active_twin_status(): void
    {
        [$user, $company] = $this->userWithCompanyAndIntegration();

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('health.twin_status', 'active')
                ->where('health.twin_health_score', 80)
            );
    }

    public function test_dashboard_shows_pending_recommendation(): void
    {
        [$user, $company] = $this->userWithCompanyAndIntegration();

        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_type' => 'email_campaign',
            'rationale_display' => ['why_now' => 'Great timing'],
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pending_recommendation')
            );
    }

    public function test_has_campaign_history_is_false_for_a_brand_new_company(): void
    {
        // Drives the Dashboard's progressive reveal (UI rethink Workstream
        // C.3) — a company with zero campaigns ever created shouldn't share
        // a row with a guaranteed-empty Campaigns card.
        [$user] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('has_campaign_history', false));
    }

    public function test_has_campaign_history_is_true_once_a_campaign_exists(): void
    {
        [$user, $company] = $this->userWithCompanyAndIntegration();

        Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'title' => 'Silver Age Email Campaign',
            'campaign_type' => 'email_campaign',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('has_campaign_history', true));
    }

    /** @return array{User, Company} */
    private function userWithCompanyAndIntegration(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://test.com'],
            'status' => 'active',
        ]);

        return [$user, $company];
    }
}
