<?php

namespace Tests\Feature\Opportunity;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\Opportunity\Detectors\FeaturedItemDetector;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class FeaturedItemDetectorTest extends TestCase
{
    use RefreshDatabase;

    private FeaturedItemDetector $detector;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new FeaturedItemDetector();
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

    public function test_returns_empty_when_no_featured_items(): void
    {
        $brain = $this->makeBrain(collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_detects_item_never_promoted(): void
    {
        $item = $this->makeCatalogItem(['promoted_at' => null]);
        $brain = $this->makeBrain(collect([$item]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertInstanceOf(OpportunityCandidate::class, $candidates->first());
        $this->assertSame('featured_item', $candidates->first()->type);
        $this->assertSame('catalog_item', $candidates->first()->subjectType);
        $this->assertSame($item->id, $candidates->first()->subjectId);
    }

    public function test_skips_item_promoted_within_cooldown(): void
    {
        $item = $this->makeCatalogItem(['promoted_at' => now()->subDays(3)]);
        $brain = $this->makeBrain(collect([$item]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_detects_item_promoted_outside_cooldown(): void
    {
        $item = $this->makeCatalogItem(['promoted_at' => now()->subDays(20)]);
        $brain = $this->makeBrain(collect([$item]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
    }

    public function test_applies_extended_cooldown_for_high_value_items(): void
    {
        // High value = price >= 10000, cooldown = 45 days
        $item = $this->makeCatalogItem([
            'price' => 15000.00,
            'promoted_at' => now()->subDays(20), // within 45-day high-value cooldown
        ]);
        $brain = $this->makeBrain(collect([$item]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_high_value_item_outside_extended_cooldown_is_detected(): void
    {
        $item = $this->makeCatalogItem([
            'price' => 15000.00,
            'promoted_at' => now()->subDays(50),
        ]);
        $brain = $this->makeBrain(collect([$item]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame(85, $candidates->first()->relevanceScore);
    }

    private function makeBrain(Collection $items): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
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
            'title' => 'Test Item',
            'status' => 'active',
            'price' => 500.00,
            'promoted_at' => null,
        ], $overrides));
    }
}
