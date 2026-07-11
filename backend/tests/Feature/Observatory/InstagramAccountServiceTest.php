<?php

namespace Tests\Feature\Observatory;

use App\Models\Company;
use App\Models\InstagramAccount;
use App\Models\Integration;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\Observatory\InstagramAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(string $companyId): Integration
    {
        return Integration::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token-123'],
            'status' => 'active',
        ]);
    }

    public function test_sync_snapshot_creates_a_new_account(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company->id);

        $service = new InstagramAccountService();
        $account = $service->syncSnapshot($integration, [
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => 'CBB Auctions',
            'follower_count' => 4210,
            'following_count' => 180,
        ]);

        $this->assertInstanceOf(InstagramAccount::class, $account);
        $this->assertSame($company->id, $account->company_id);
        $this->assertSame($integration->id, $account->integration_id);
        $this->assertSame('cbb_auctions', $account->username);
        $this->assertNotNull($account->last_synced_at);
    }

    public function test_sync_snapshot_updates_the_existing_account_for_the_same_integration(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company->id);
        $service = new InstagramAccountService();

        $first = $service->syncSnapshot($integration, ['account_id' => '1', 'username' => 'cbb_auctions', 'follower_count' => 100]);
        $second = $service->syncSnapshot($integration, ['account_id' => '1', 'username' => 'cbb_auctions', 'follower_count' => 150]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InstagramAccount::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(150, $second->fresh()->follower_count);
    }

    public function test_instagram_accounts_are_isolated_per_company(): void
    {
        $service = $this->app->make(CompanyService::class);
        $user = User::factory()->create();

        $companyA = $service->create($user, ['name' => 'Company A']);
        $companyB = $service->create($user, ['name' => 'Company B']);

        $accountService = new InstagramAccountService();
        $accountService->syncSnapshot($this->makeIntegration($companyA->id), [
            'account_id' => 'a', 'username' => 'company_a',
        ]);
        $accountService->syncSnapshot($this->makeIntegration($companyB->id), [
            'account_id' => 'b', 'username' => 'company_b',
        ]);

        $this->app->instance('current_company_id', $companyA->id);

        $this->assertCount(1, InstagramAccount::all());
        $this->assertSame('company_a', InstagramAccount::first()->username);
    }
}
