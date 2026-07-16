<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Models\Opportunity;
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

    public function test_recent_execution_channel_includes_the_linked_marketing_channels_publishing_status(): void
    {
        [$user, $company] = $this->userWithCompanyAndIntegration();

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'facebook',
            'name' => 'Facebook',
        ]);

        MarketingChannel::create([
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'type' => 'facebook',
            'display_name' => 'Facebook',
            'status' => 'active',
            'importance' => 'secondary',
            'objective' => ['awareness'],
            'posting_frequency' => 'weekly',
            'is_connected' => true,
            'supports_publishing' => false,
        ]);

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

        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'status' => 'draft',
        ]);

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
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recent_executions.0.channel.type', 'facebook')
                ->where('recent_executions.0.channel.marketing_channel.supports_publishing', false)
            );
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
