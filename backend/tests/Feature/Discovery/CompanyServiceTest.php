<?php

namespace Tests\Feature\Discovery;

use App\Models\Channel;
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
        $this->assertDatabaseCount('channels', 1);
    }

    public function test_seeds_a_default_active_blog_channel(): void
    {
        // DecisionEngine::evaluate() refuses to commit a Decision (and
        // therefore never produces a Recommendation) for a company with
        // zero active Channel rows — without this seed, a company can have
        // healthy Facts/Knowledge/Opportunities and still never see a
        // Recommendation. See CHANGELOG "no active channels" fix.
        $owner = User::factory()->create();
        $company = $this->service->create($owner, ['name' => 'Test Co']);

        $channel = Channel::where('company_id', $company->id)->first();

        $this->assertNotNull($channel);
        $this->assertSame('blog', $channel->type);
        $this->assertTrue($channel->is_active);
    }

    public function test_generates_a_unique_slug_when_another_company_already_has_the_same_name(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();

        $first = $this->service->create($firstOwner, ['name' => 'Acme Comics']);
        $second = $this->service->create($secondOwner, ['name' => 'Acme Comics']);

        $this->assertSame('acme-comics', $first->slug);
        $this->assertSame('acme-comics-2', $second->slug);
    }

    public function test_generates_a_unique_slug_against_a_soft_deleted_company(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();

        $first = $this->service->create($firstOwner, ['name' => 'Acme Comics']);
        $first->delete();

        $second = $this->service->create($secondOwner, ['name' => 'Acme Comics']);

        $this->assertSame('acme-comics-2', $second->slug);
    }
}
