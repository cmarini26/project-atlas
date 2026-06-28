<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
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

    public function test_dashboard_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Dashboard'));
    }

    public function test_dashboard_includes_twin_status_when_no_twin(): void
    {
        [$user] = $this->userWithCompany();

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
        [$user, $company] = $this->userWithCompany();

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
        [$user, $company] = $this->userWithCompany();

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

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }
}
