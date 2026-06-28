<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Decision;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/analytics')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Analytics/Index'));
    }

    public function test_index_includes_campaign_snapshots(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'snapshot_type' => 'final',
            'snapshotted_at' => now(),
            'channels_included' => [],
            'actual_kpis' => ['reach' => 500],
            'performance_rating' => 'good',
        ]);

        $this->actingAs($user)
            ->get('/app/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('campaign_snapshots', 1));
    }

    public function test_show_renders_campaign_analytics(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'snapshot_type' => 'final',
            'snapshotted_at' => now(),
            'channels_included' => [],
            'actual_kpis' => ['reach' => 500],
            'performance_rating' => 'good',
        ]);

        $this->actingAs($user)
            ->get("/app/analytics/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Analytics/Show'));
    }

    public function test_show_returns_404_for_other_company_campaign(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $campaign = $this->createCampaign($other);

        $this->actingAs($user)
            ->get("/app/analytics/{$campaign->id}")
            ->assertNotFound();
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    private function createCampaign(Company $company): Campaign
    {
        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'featured_item',
            'title' => 'Test Opportunity',
            'description' => 'A test opportunity.',
            'status' => 'selected',
            'composite_score' => 80,
            'relevance_score' => 80,
            'timing_score' => 80,
            'confidence_score' => 80,
            'urgency_score' => 80,
            'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [],
            'rationale' => [],
            'decided_at' => now(),
        ]);

        return Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'social_post',
            'title' => 'Test Campaign',
            'status' => 'completed',
        ]);
    }
}
