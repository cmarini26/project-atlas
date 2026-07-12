<?php

namespace Tests\Feature\App;

use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPresenceControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── index ────────────────────────────────────────────────────────────────

    public function test_index_requires_auth(): void
    {
        $this->get('/app/settings/marketing-presence')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Settings/MarketingPresence/Index'));
    }

    public function test_index_lists_declared_channels_with_capability(): void
    {
        [$user, $company] = $this->userWithCompany();

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'display_name' => 'Acme Instagram',
            'objective' => ['awareness'],
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('channels', 1)
                ->where('channels.0.display_name', 'Acme Instagram')
                ->where('channels.0.type', 'instagram')
                ->where('channels.0.capability', 'declared')
            );
    }

    public function test_index_reports_connected_capability_for_a_linked_active_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        $realChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_id' => $realChannel->id,
            'type' => 'email',
            'display_name' => 'Newsletter',
            'objective' => ['retention'],
            'is_connected' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertInertia(fn ($page) => $page->where('channels.0.capability', 'connected'));
    }

    public function test_index_only_shows_the_acting_companys_channels(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'facebook',
            'display_name' => "Other Co's Facebook",
            'objective' => ['awareness'],
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertInertia(fn ($page) => $page->has('channels', 0));
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_store_declares_a_new_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/marketing-presence', [
                'type' => 'linkedin',
                'display_name' => 'Acme LinkedIn',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id,
            'type' => 'linkedin',
            'display_name' => 'Acme LinkedIn',
        ]);
    }

    public function test_store_accepts_an_optional_handle_or_url(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/settings/marketing-presence', [
            'type' => 'instagram',
            'display_name' => 'Acme Instagram',
            'handle_or_url' => '@acme',
        ]);

        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id,
            'handle_or_url' => '@acme',
        ]);
    }

    public function test_store_creates_no_technical_channel_record(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/settings/marketing-presence', [
            'type' => 'facebook',
            'display_name' => 'Acme Facebook',
        ]);

        $this->assertDatabaseCount('channels', 0);
    }

    public function test_store_requires_display_name(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/marketing-presence', ['type' => 'instagram'])
            ->assertSessionHasErrors('display_name');
    }

    public function test_store_rejects_an_unknown_type(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/marketing-presence', ['type' => 'carrier-pigeon', 'display_name' => 'Pigeons'])
            ->assertSessionHasErrors('type');
    }

    public function test_store_allows_a_second_channel_of_the_same_type(): void
    {
        [$user, $company] = $this->userWithCompany();

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'display_name' => 'Main Instagram',
            'objective' => ['awareness'],
        ]);

        $this->actingAs($user)
            ->post('/app/settings/marketing-presence', ['type' => 'instagram', 'display_name' => 'Regional Instagram'])
            ->assertRedirect();

        $this->assertSame(2, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'instagram')->count());
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_changes_status(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['status' => 'occasional'])
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'status' => 'occasional']);
    }

    public function test_update_changes_importance(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['importance' => 'primary'])
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'importance' => 'primary']);
    }

    public function test_update_changes_objective(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['objective' => ['leads', 'sales']])
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame(['leads', 'sales'], $channel->objective);
    }

    public function test_update_rejects_an_empty_objective_array(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['objective' => []])
            ->assertSessionHasErrors('objective');
    }

    public function test_update_rejects_an_unknown_status(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['status' => 'on-fire'])
            ->assertSessionHasErrors('status');
    }

    public function test_update_ignores_company_id_and_channel_id(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $realChannel = Channel::withoutGlobalScopes()->create(['company_id' => $company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true]);

        $this->actingAs($user)->patch("/app/settings/marketing-presence/{$channel->id}", [
            'company_id' => $other->id,
            'channel_id' => $realChannel->id,
            'status' => 'occasional',
        ]);

        $channel->refresh();
        $this->assertSame($company->id, $channel->company_id);
        $this->assertNull($channel->channel_id);
    }

    public function test_update_is_denied_for_a_channel_belonging_to_another_company(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $channel = $this->declaredChannel($other);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['status' => 'occasional'])
            ->assertNotFound();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'status' => 'active']);
    }

    // ── destroy (disable) / reactivate ──────────────────────────────────────

    public function test_destroy_sets_status_inactive_without_deleting_the_row(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company);

        $this->actingAs($user)
            ->delete("/app/settings/marketing-presence/{$channel->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'status' => 'inactive']);
    }

    public function test_reactivate_via_status_update_sets_status_active(): void
    {
        [$user, $company] = $this->userWithCompany();
        $channel = $this->declaredChannel($company, ['status' => 'inactive']);

        $this->actingAs($user)
            ->patch("/app/settings/marketing-presence/{$channel->id}", ['status' => 'active'])
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'status' => 'active']);
    }

    public function test_destroy_is_denied_for_a_channel_belonging_to_another_company(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $channel = $this->declaredChannel($other);

        $this->actingAs($user)
            ->delete("/app/settings/marketing-presence/{$channel->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('marketing_channels', ['id' => $channel->id, 'status' => 'active']);
    }

    // ── instagram_insights (Milestone 12 Phase 2) ───────────────────────────────

    public function test_instagram_insights_is_null_without_an_instagram_integration(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_insights', null));
    }

    public function test_instagram_insights_is_null_when_no_content_facts_exist_yet(): void
    {
        [$user, $company] = $this->userWithCompany();

        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'name' => 'Instagram',
            'config' => ['access_token' => 'token'], 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_insights', null));
    }

    public function test_instagram_insights_surfaces_content_facts(): void
    {
        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'name' => 'Instagram',
            'config' => ['access_token' => 'token'], 'status' => 'active',
            'last_run_at' => now(),
        ]);

        Fact::create([
            'company_id' => $company->id, 'key' => 'instagram.username', 'value' => 'cbb_auctions',
            'data_type' => 'string', 'confidence' => 100, 'is_current' => true, 'valid_from' => now(),
        ]);
        Fact::create([
            'company_id' => $company->id, 'key' => 'instagram.posting_cadence', 'value' => 2.5,
            'data_type' => 'float', 'confidence' => 90, 'is_current' => true, 'valid_from' => now(),
        ]);
        Fact::create([
            'company_id' => $company->id, 'key' => 'instagram.media_mix', 'value' => ['IMAGE' => 3],
            'data_type' => 'json', 'confidence' => 100, 'is_current' => true, 'valid_from' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instagram_insights.username', 'cbb_auctions')
                ->where('instagram_insights.posting_cadence', 2.5)
                ->where('instagram_insights.media_mix.IMAGE', 3)
                ->where('instagram_insights.last_synced_at', (string) $integration->last_run_at)
            );
    }

    public function test_instagram_insights_omits_superseded_facts(): void
    {
        [$user, $company] = $this->userWithCompany();

        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'name' => 'Instagram',
            'config' => ['access_token' => 'token'], 'status' => 'active',
        ]);

        Fact::create([
            'company_id' => $company->id, 'key' => 'instagram.posting_cadence', 'value' => 1.0,
            'data_type' => 'float', 'confidence' => 90, 'is_current' => false, 'valid_from' => now()->subDay(),
        ]);
        Fact::create([
            'company_id' => $company->id, 'key' => 'instagram.media_mix', 'value' => ['IMAGE' => 1],
            'data_type' => 'json', 'confidence' => 100, 'is_current' => true, 'valid_from' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_insights.posting_cadence', null));
    }

    public function test_instagram_insights_does_not_leak_across_companies(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        Integration::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'type' => 'instagram', 'name' => 'Instagram',
            'config' => ['access_token' => 'token'], 'status' => 'active',
        ]);
        Fact::create([
            'company_id' => $other->id, 'key' => 'instagram.media_mix', 'value' => ['IMAGE' => 9],
            'data_type' => 'json', 'confidence' => 100, 'is_current' => true, 'valid_from' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/settings/marketing-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_insights', null));
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    /** @param array<string, mixed> $overrides */
    private function declaredChannel(Company $company, array $overrides = []): MarketingChannel
    {
        return MarketingChannel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $company->id,
            'type' => 'instagram',
            'display_name' => 'Acme Instagram',
            'objective' => ['awareness'],
        ], $overrides));
    }
}
