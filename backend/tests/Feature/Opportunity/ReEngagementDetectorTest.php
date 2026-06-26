<?php

namespace Tests\Feature\Opportunity;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\Opportunity\Detectors\ReEngagementDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReEngagementDetectorTest extends TestCase
{
    use RefreshDatabase;

    private ReEngagementDetector $detector;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private CatalogItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new ReEngagementDetector();
        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);
        $this->item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Active Item',
            'status' => 'active',
        ]);
    }

    public function test_returns_empty_when_no_featured_items(): void
    {
        $brain = $this->makeBrain(collect(), collect(), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_returns_empty_when_gap_below_threshold(): void
    {
        $fact = $this->makeDaysFact(7); // under 14-day threshold
        $brain = $this->makeBrain(collect([$this->item]), collect([$fact]), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_detects_gap_above_threshold_from_fact(): void
    {
        $fact = $this->makeDaysFact(21);
        $brain = $this->makeBrain(collect([$this->item]), collect([$fact]), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame('re_engagement', $candidates->first()->type);
    }

    public function test_falls_back_to_recent_campaigns_when_no_fact(): void
    {
        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Past Campaign',
            'status' => 'completed',
        ]);
        DB::table('campaigns')->where('id', $campaign->id)->update(['created_at' => now()->subDays(20)]);
        $campaign->refresh();

        $brain = $this->makeBrain(collect([$this->item]), collect(), collect([$campaign]));

        $candidates = $this->detector->detect($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame('re_engagement', $candidates->first()->type);
    }

    public function test_returns_999_days_when_no_campaigns_ever(): void
    {
        $brain = $this->makeBrain(collect([$this->item]), collect(), collect());

        $candidates = $this->detector->detect($this->company, $brain);

        // 999 days exceeds all thresholds — should detect with high timing/urgency
        $this->assertCount(1, $candidates);
        $this->assertSame(90, $candidates->first()->timingScore);
    }

    private function makeBrain(Collection $items, Collection $facts, Collection $campaigns): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: $facts,
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: $this->catalog,
            featuredItems: $items,
            recentCampaigns: $campaigns,
        );
    }

    private function makeDaysFact(int $days): Fact
    {
        return Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'marketing.days_since_last_campaign',
            'value' => $days,
            'data_type' => 'integer',
            'confidence' => 100,
            'is_current' => true,
        ]);
    }
}
