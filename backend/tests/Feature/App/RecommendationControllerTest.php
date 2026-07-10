<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\MarketingChannel;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/recommendations')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/recommendations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Recommendations/Index'));
    }

    public function test_index_includes_pending_and_recent(): void
    {
        [$user, $company] = $this->userWithCompany();

        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_type' => 'social_post',
            'status' => 'pending',
        ]);

        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_type' => 'email_campaign',
            'status' => 'approved',
        ]);

        $this->actingAs($user)
            ->get('/app/recommendations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pending', 1)
                ->has('recent', 1)
            );
    }

    public function test_show_renders_recommendation_page(): void
    {
        [$user, $company] = $this->userWithCompany();

        $rec = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_type' => 'social_post',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Recommendations/Show'));
    }

    public function test_show_includes_content_asset_channel_for_approval_confirmation(): void
    {
        // The frontend approval confirmation dialog names the content and its
        // destination channel — the show payload must carry the channel type
        // for every content asset, not just the campaign.
        [$user, $company] = $this->userWithCompany();

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'blog',
            'name' => 'Blog',
            'is_active' => true,
        ]);

        $rec = $this->pendingRecommendation($company);

        ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $rec->campaign_id,
            'channel_id' => $channel->id,
            'type' => 'blog_post',
            'title' => 'Rare finds this week',
            'body' => 'Body copy',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertInertia(fn ($page) => $page
                ->where('content_assets.0.title', 'Rare finds this week')
                ->where('content_assets.0.channel.type', 'blog')
            );
    }

    public function test_show_includes_channel_mix_with_a_supporting_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'type' => 'email',
            'display_name' => 'Acme Newsletter',
            'status' => 'active',
            'importance' => 'secondary',
            'objective' => ['awareness'],
        ]);

        $rec = $this->pendingRecommendation($company, [$channel->id]);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertInertia(fn ($page) => $page
                ->where('channel_mix.supporting.0.name', 'Acme Newsletter')
                ->where('channel_mix.primary', [])
            );
    }

    public function test_show_includes_declared_draft_only_channel_in_mix(): void
    {
        [$user, $company] = $this->userWithCompany();

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'print',
            'display_name' => 'Local Paper Ad',
            'status' => 'active',
            'objective' => ['awareness'],
        ]);

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertInertia(fn ($page) => $page->where('channel_mix.draft_only.0.name', 'Local Paper Ad'));
    }

    public function test_show_channel_mix_never_leaks_another_companys_marketing_channels(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-mc']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'print',
            'display_name' => "Other Co's Print Ad",
            'status' => 'active',
            'objective' => ['awareness'],
        ]);

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertInertia(fn ($page) => $page->where('channel_mix.draft_only', []));
    }

    public function test_show_returns_404_for_other_company_recommendation(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $rec = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'campaign_type' => 'email_campaign',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get("/app/recommendations/{$rec->id}")
            ->assertNotFound();
    }

    public function test_owner_can_approve_recommendation(): void
    {
        [$user, $company] = $this->userWithCompany('owner');

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->post("/app/recommendations/{$rec->id}/approve")
            ->assertRedirect('/app/recommendations');

        $this->assertDatabaseHas('recommendations', [
            'id' => $rec->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_approve_recommendation(): void
    {
        [$user, $company] = $this->userWithCompany('admin');

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->post("/app/recommendations/{$rec->id}/approve")
            ->assertRedirect('/app/recommendations');

        $this->assertDatabaseHas('recommendations', [
            'id' => $rec->id,
            'status' => 'approved',
        ]);
    }

    public function test_member_cannot_approve_recommendation(): void
    {
        [$user, $company] = $this->userWithCompany('member');

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->post("/app/recommendations/{$rec->id}/approve")
            ->assertForbidden();
    }

    public function test_owner_can_reject_recommendation(): void
    {
        [$user, $company] = $this->userWithCompany('owner');

        $rec = $this->pendingRecommendation($company);

        $this->actingAs($user)
            ->post("/app/recommendations/{$rec->id}/reject", ['notes' => 'Not right now'])
            ->assertRedirect('/app/recommendations');

        $this->assertDatabaseHas('recommendations', [
            'id' => $rec->id,
            'status' => 'rejected',
        ]);
    }

    public function test_approve_is_denied_for_other_company_recommendation(): void
    {
        [$user] = $this->userWithCompany('owner');
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);

        $rec = $this->pendingRecommendation($other);

        $this->actingAs($user)
            ->post("/app/recommendations/{$rec->id}/approve")
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

    /** @param list<string> $channelIds */
    private function pendingRecommendation(Company $company, array $channelIds = []): Recommendation
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
            'channel_ids' => $channelIds,
            'rationale' => ['why_now' => 'Good timing'],
            'expected_impact' => ['reach' => '1000'],
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'social_post',
            'title' => 'Test Campaign',
            'status' => 'draft',
        ]);

        return Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_id' => $campaign->id,
            'campaign_type' => 'social_post',
            'rationale_display' => ['why_now' => 'Good timing'],
            'status' => 'pending',
        ]);
    }
}
