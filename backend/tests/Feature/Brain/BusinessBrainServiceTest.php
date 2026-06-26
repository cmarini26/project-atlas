<?php

namespace Tests\Feature\Brain;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessBrainServiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessBrainService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(BusinessBrainService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'mixed',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
        ]);
    }

    public function test_assembles_business_brain_with_company_and_twin(): void
    {
        $brain = $this->service->for($this->company);

        $this->assertInstanceOf(BusinessBrain::class, $brain);
        $this->assertSame($this->company->id, $brain->company->id);
        $this->assertEquals('active', $brain->twin->status);
    }

    public function test_includes_current_facts(): void
    {
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'value' => json_encode('CBB Auctions'),
            'data_type' => 'string',
            'confidence' => 95,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        $brain = $this->service->for($this->company);

        $this->assertCount(1, $brain->activeFacts);
        $this->assertEquals('business.name', $brain->activeFacts->first()->key);
    }

    public function test_excludes_superseded_facts(): void
    {
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'value' => json_encode('Old Name'),
            'data_type' => 'string',
            'confidence' => 70,
            'is_current' => false,
            'valid_from' => now()->subDay(),
        ]);

        $brain = $this->service->for($this->company);

        $this->assertCount(0, $brain->activeFacts);
    }

    public function test_includes_active_knowledge(): void
    {
        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'context',
            'subject' => 'business',
            'body' => 'Business context',
            'confidence' => 85,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        $brain = $this->service->for($this->company);

        $this->assertCount(1, $brain->activeKnowledge);
    }

    public function test_includes_catalog(): void
    {
        $brain = $this->service->for($this->company);

        $this->assertNotNull($brain->catalog);
        $this->assertEquals($this->company->id, $brain->catalog->company_id);
    }

    public function test_featured_items_and_campaigns_are_empty_in_milestone_3(): void
    {
        $brain = $this->service->for($this->company);

        $this->assertCount(0, $brain->featuredItems);
        $this->assertCount(0, $brain->recentCampaigns);
    }
}
