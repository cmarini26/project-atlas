<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Decision;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/campaigns')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Campaigns/Index'));
    }

    public function test_index_returns_company_campaigns(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->createCampaign($company);

        $this->actingAs($user)
            ->get('/app/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('campaigns.data', 1)
                ->where('campaigns.total', 1)
            );
    }

    public function test_index_excludes_other_company_campaigns(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $this->createCampaign($other);

        $this->actingAs($user)
            ->get('/app/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('campaigns.data', 0));
    }

    public function test_show_renders_campaign_page(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Campaigns/Show'));
    }

    public function test_show_returns_404_for_other_company_campaign(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-2']);
        $campaign = $this->createCampaign($other);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
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
            'status' => 'draft',
        ]);
    }
}
