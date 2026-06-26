<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\User;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CompanyService::class);
    }

    public function test_creates_company_with_all_required_entities(): void
    {
        $owner = User::factory()->create();

        $company = $this->service->create($owner, [
            'name' => 'CBB Auctions',
            'website_url' => 'https://cbbauctions.com',
        ]);

        $this->assertInstanceOf(Company::class, $company);
        $this->assertEquals('CBB Auctions', $company->name);
        $this->assertEquals('cbb-auctions', $company->slug);
    }

    public function test_creates_catalog_for_company(): void
    {
        $owner = User::factory()->create();
        $company = $this->service->create($owner, ['name' => 'Test Co']);

        $this->assertDatabaseHas('catalogs', [
            'company_id' => $company->id,
            'type' => 'mixed',
        ]);
    }

    public function test_creates_digital_twin_with_initializing_status(): void
    {
        $owner = User::factory()->create();
        $company = $this->service->create($owner, ['name' => 'Test Co']);

        $twin = DigitalTwin::withoutGlobalScopes()->where('company_id', $company->id)->first();

        $this->assertNotNull($twin);
        $this->assertEquals('initializing', $twin->status);
    }

    public function test_creates_owner_membership(): void
    {
        $owner = User::factory()->create();
        $company = $this->service->create($owner, ['name' => 'Test Co']);

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_creates_all_entities_atomically(): void
    {
        $owner = User::factory()->create();

        $this->service->create($owner, ['name' => 'Atomic Co']);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('catalogs', 1);
        $this->assertDatabaseCount('digital_twins', 1);
        $this->assertDatabaseCount('company_memberships', 1);
    }
}
