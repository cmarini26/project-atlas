<?php

namespace Tests\Feature\App;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Models\MarketingChannel;
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

    public function test_show_includes_the_linked_marketing_channels_publishing_status_on_content_assets_and_executions(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

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
            'supports_publishing' => true,
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
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('content_assets.0.channel.marketing_channel.supports_publishing', true)
                ->where('executions.0.channel.marketing_channel.supports_publishing', true)
            );
    }

    public function test_show_includes_content_asset_media(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'blog',
            'name' => 'Blog',
            'is_active' => true,
        ]);

        ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'blog_post',
            'body' => 'Body copy',
            'media' => [['url' => 'https://example.com/hero.jpg']],
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertInertia(fn ($page) => $page
                ->where('content_assets.0.media.0.url', 'https://example.com/hero.jpg')
            );
    }

    public function test_campaign_may_update_selected_channels_before_publishing_starts(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $firstChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);
        $secondChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'blog',
            'name' => 'Blog',
            'is_active' => true,
        ]);

        Decision::withoutGlobalScopes()->findOrFail($campaign->decision_id)->update([
            'channel_ids' => [$firstChannel->id, $secondChannel->id],
        ]);

        $firstAsset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $firstChannel->id,
            'type' => 'email',
            'body' => 'Email body',
            'status' => 'draft',
        ]);
        $secondAsset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $secondChannel->id,
            'type' => 'blog_post',
            'body' => 'Blog body',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/channels", ['selected_content_asset_ids' => [$secondAsset->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('content_assets', ['id' => $firstAsset->id, 'status' => 'archived']);
        $this->assertDatabaseHas('content_assets', ['id' => $secondAsset->id, 'status' => 'draft']);
        $this->assertSame([$secondChannel->id], Decision::withoutGlobalScopes()->findOrFail($campaign->decision_id)->channel_ids);
    }

    public function test_campaign_cannot_update_selected_channels_after_execution_exists(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $execution = $this->makeEmailExecution($company, $campaign);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/channels", ['selected_content_asset_ids' => [$execution->content_asset_id]])
            ->assertForbidden();
    }

    public function test_email_campaign_may_select_a_company_owned_audience(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/email-audience", ['email_audience_id' => $audience->id])
            ->assertRedirect();

        $this->assertSame($audience->id, $campaign->fresh()->email_audience_id);
    }

    public function test_campaign_cannot_select_another_companys_audience(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);
        $otherAudience = EmailAudience::create(['company_id' => $other->id, 'name' => 'Other Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/email-audience", ['email_audience_id' => $otherAudience->id])
            ->assertNotFound();

        $this->assertNull($campaign->fresh()->email_audience_id);
    }

    public function test_selecting_no_audience_clears_it(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'email_audience_id' => $audience->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/email-audience", ['email_audience_id' => null])
            ->assertRedirect();

        $this->assertNull($campaign->fresh()->email_audience_id);
    }

    public function test_non_email_campaign_behavior_is_unaffected_by_audience_targeting(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.selected', null)
                ->where('email_audience_selector.audiences', [])
            );
    }

    public function test_empty_audience_cannot_accidentally_begin_real_sending(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Empty List', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/campaigns/{$campaign->id}/email-audience", ['email_audience_id' => $audience->id]);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.selected.member_count', 0)
            );

        // No Execution/send was triggered by merely selecting an empty
        // audience — targeting a campaign never itself queues a send.
        $this->assertDatabaseCount('executions', 0);
    }

    public function test_selected_audience_is_included_safely_in_page_props(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create([
            'company_id' => $company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
        ]);
        $audience->members()->attach($contact->id);
        $campaign->update(['email_audience_id' => $audience->id]);

        $response = $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.selected.name', 'Newsletter')
                ->where('email_audience_selector.selected.member_count', 1)
            );

        // No raw contact emails/PII leak into the campaign page props —
        // only the audience's name and a count.
        $this->assertStringNotContainsString('a@example.com', $response->getContent() ?: '');
    }

    public function test_recipient_outcomes_is_null_when_no_send_has_ever_been_queued(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.recipient_outcomes', null)
            );
    }

    public function test_recipient_outcomes_aggregates_snapshot_statuses_honestly(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);
        $execution = $this->makeEmailExecution($company, $campaign);

        EmailRecipientSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'execution_id' => $execution->id,
            'email' => 'sent1@example.com', 'status' => 'sent', 'provider_message_id' => 'pm-1',
        ]);
        EmailRecipientSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'execution_id' => $execution->id,
            'email' => 'sent2@example.com', 'status' => 'sent', 'provider_message_id' => 'pm-2',
        ]);
        EmailRecipientSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'execution_id' => $execution->id,
            'email' => 'failed@example.com', 'status' => 'failed', 'skipped_reason' => 'Invalid address',
        ]);
        EmailRecipientSnapshot::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'execution_id' => $execution->id,
            'email' => 'pending@example.com', 'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.recipient_outcomes.pending', 1)
                ->where('email_audience_selector.recipient_outcomes.accepted', 2)
                ->where('email_audience_selector.recipient_outcomes.failed', 1)
                ->where('email_audience_selector.recipient_outcomes.skipped', 0)
                ->where('email_audience_selector.recipient_outcomes.total', 4)
            );

        // Aggregate counts only — no raw recipient address or failure
        // reason text ever crosses into the page props.
        $content = $response->getContent() ?: '';
        $this->assertStringNotContainsString('sent1@example.com', $content);
        $this->assertStringNotContainsString('sent2@example.com', $content);
        $this->assertStringNotContainsString('failed@example.com', $content);
        $this->assertStringNotContainsString('pending@example.com', $content);
        $this->assertStringNotContainsString('Invalid address', $content);
    }

    public function test_recipient_outcomes_do_not_leak_another_companys_counts(): void
    {
        [$user, $company] = $this->userWithCompany();
        $campaign = $this->createCampaign($company);

        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-outcomes']);
        $otherCampaign = $this->createCampaign($other);
        $otherExecution = $this->makeEmailExecution($other, $otherCampaign);

        EmailRecipientSnapshot::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'campaign_id' => $otherCampaign->id, 'execution_id' => $otherExecution->id,
            'email' => 'other@example.com', 'status' => 'sent', 'provider_message_id' => 'pm-other',
        ]);

        $this->actingAs($user)
            ->get("/app/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_audience_selector.recipient_outcomes', null)
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

    private function makeEmailExecution(Company $company, Campaign $campaign): Execution
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'channel_id' => $channel->id,
            'type' => 'email', 'body' => 'Body.', 'status' => 'scheduled',
        ]);

        return Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'campaign_id' => $campaign->id, 'content_asset_id' => $asset->id,
            'channel_id' => $channel->id, 'status' => 'completed', 'idempotency_key' => 'test-key-'.uniqid(),
        ]);
    }
}
