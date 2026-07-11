<?php

namespace Tests\Feature\App;

use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InstagramAccount;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/settings')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Settings'));
    }

    public function test_index_includes_company_and_integrations(): void
    {
        [$user, $company] = $this->userWithCompany();

        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website Scraper',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('company.name', 'Test Co')
                ->has('integrations', 1)
            );
    }

    public function test_update_saves_company_name(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', [
                'name' => 'Updated Business Name',
                'industry' => 'retail',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Business Name',
        ]);
    }

    public function test_update_requires_name(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', ['industry' => 'retail'])
            ->assertSessionHasErrors('name');
    }

    public function test_sync_integration_dispatches_job(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertRedirect();

        Bus::assertDispatched(SyncIntegration::class, fn ($job) => $job->integration->id === $integration->id);
    }

    public function test_sync_integration_is_denied_for_other_company(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertNotFound();
    }

    public function test_index_includes_null_instagram_account_when_not_connected(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_account', null));
    }

    public function test_index_includes_instagram_account_snapshot_when_connected(): void
    {
        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'status' => 'active',
            'config' => ['access_token' => 'token-123'],
        ]);

        InstagramAccount::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => 'CBB Auctions',
            'follower_count' => 4210,
            'following_count' => 180,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instagram_account.username', 'cbb_auctions')
                ->where('instagram_account.follower_count', 4210)
            );
    }

    public function test_connect_instagram_creates_an_integration_and_dispatches_sync(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', ['access_token' => 'token-abc'])
            ->assertRedirect();

        $this->assertDatabaseHas('integrations', [
            'company_id' => $company->id,
            'type' => 'instagram',
            'status' => 'active',
        ]);

        Bus::assertDispatched(SyncIntegration::class, fn ($job) => $job->integration->company_id === $company->id);
    }

    public function test_connect_instagram_requires_an_access_token(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', [])
            ->assertSessionHasErrors('access_token');
    }

    public function test_connect_instagram_reuses_the_existing_integration_on_reconnect(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $existing = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'status' => 'error',
            'config' => ['access_token' => 'old-token'],
            'last_error' => 'Invalid token',
        ]);

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', ['access_token' => 'new-token'])
            ->assertRedirect();

        $this->assertSame(1, Integration::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'instagram')->count());

        $existing->refresh();
        $this->assertSame('active', $existing->status);
        $this->assertNull($existing->last_error);
        $this->assertSame('new-token', $existing->config['access_token']);
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
