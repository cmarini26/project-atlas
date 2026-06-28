<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/app/publishing')->assertRedirect('/login');
    }

    public function test_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/publishing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Publishing'));
    }

    public function test_returns_executions_for_company(): void
    {
        [$user, $company] = $this->userWithCompany();

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
        ]);

        $campaign = $this->createCampaign($company);

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'social_post',
            'body' => 'Test post body',
            'status' => 'draft',
        ]);

        Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $channel->id,
            'status' => 'pending',
            'idempotency_key' => 'test-key-'.uniqid(),
        ]);

        $this->actingAs($user)
            ->get('/app/publishing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('executions.data', 1)
                ->where('executions.total', 1)
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
