<?php

namespace Tests\Feature\Opportunity;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\Opportunity\Detectors\UrgencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UrgencyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private UrgencyDetector $detector;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new UrgencyDetector();
        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);
    }

    public function test_returns_empty_when_nothing_is_expiring(): void
    {
        $brain = $this->makeBrain(collect(), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_detects_item_expiring_within_48h(): void
    {
        $item = $this->makeCatalogItem(['expires_at' => now()->addHours(24)]);
        $brain = $this->makeBrain(collect([$item]), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame('urgency', $candidates->first()->type);
        $this->assertSame('catalog_item', $candidates->first()->subjectType);
        $this->assertSame($item->id, $candidates->first()->subjectId);
        $this->assertSame(98, $candidates->first()->urgencyScore); // ≤24h → 98
    }

    public function test_detects_item_expiring_beyond_24h_but_within_48h(): void
    {
        $item = $this->makeCatalogItem(['expires_at' => now()->addHours(36)]);
        $brain = $this->makeBrain(collect([$item]), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame(90, $candidates->first()->urgencyScore); // >24h → 90
    }

    public function test_falls_back_to_catalog_fact_when_no_item_expiry(): void
    {
        $fact = $this->makeCountFact(5);
        $brain = $this->makeBrain(collect(), collect([$fact]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame('urgency', $candidates->first()->type);
        $this->assertSame('catalog', $candidates->first()->subjectType);
    }

    public function test_item_level_takes_priority_over_catalog_fact(): void
    {
        $item = $this->makeCatalogItem(['expires_at' => now()->addHours(10)]);
        $fact = $this->makeCountFact(99);
        $brain = $this->makeBrain(collect([$item]), collect([$fact]));

        $candidates = $this->detector->detect($this->company, $brain);

        // Returns item-level candidates only, not catalog-level
        $this->assertCount(1, $candidates);
        $this->assertSame('catalog_item', $candidates->first()->subjectType);
    }

    private function makeBrain(Collection $items, Collection $facts): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: $facts,
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: $this->catalog,
            featuredItems: $items,
            recentCampaigns: collect(),
        );
    }

    private function makeCatalogItem(array $overrides = []): CatalogItem
    {
        return CatalogItem::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Expiring Item',
            'status' => 'active',
            'price' => 500.00,
        ], $overrides));
    }

    private function makeCountFact(int $count): Fact
    {
        return Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'catalog.ending_within_48h_count',
            'value' => $count,
            'data_type' => 'integer',
            'confidence' => 100,
            'is_current' => true,
        ]);
    }
}
