<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignKpiSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co', 'industry' => 'test',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'subject_type' => 'company', 'type' => 'featured_item',
            'title' => 'Test', 'description' => 'Desc', 'relevance_score' => 80, 'timing_score' => 80,
            'confidence_score' => 80, 'urgency_score' => 80, 'composite_score' => 80,
            'status' => 'selected', 'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item', 'channel_ids' => [$channel->id],
            'rationale' => ['why_now' => 'Now'], 'expected_impact' => ['target_engagement_rate' => 0.05],
            'confidence_score' => 70, 'status' => 'recommended', 'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id,
            'campaign_type' => 'featured_item', 'title' => 'Test Campaign',
            'blueprint' => [], 'blueprint_version' => '1.0', 'prompt_version' => '1.0',
            'expected_asset_count' => 1, 'generated_asset_count' => 1, 'status' => 'published',
        ]);
    }

    public function test_can_create_snapshot(): void
    {
        $snapshot = CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'interim',
            'snapshotted_at' => now(),
            'channels_included' => ['email'],
            'actual_kpis' => ['total_reach' => 500],
            'performance_rating' => 'met',
        ]);

        $this->assertDatabaseHas('campaign_kpi_snapshots', ['id' => $snapshot->id]);
        $this->assertEquals('interim', $snapshot->snapshot_type);
    }

    public function test_scope_final_filters_to_final_snapshots(): void
    {
        CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'interim', 'snapshotted_at' => now(),
            'channels_included' => ['email'], 'actual_kpis' => [],
            'performance_rating' => 'insufficient_data',
        ]);

        CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'final', 'snapshotted_at' => now()->addHour(),
            'channels_included' => ['email'], 'actual_kpis' => [],
            'performance_rating' => 'met',
        ]);

        $finals = CampaignKpiSnapshot::withoutGlobalScopes()->final()->get();

        $this->assertCount(1, $finals);
        $this->assertEquals('final', $finals->first()->snapshot_type);
    }

    public function test_has_no_updated_at_column(): void
    {
        $snapshot = CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'final', 'snapshotted_at' => now(),
            'channels_included' => ['email'], 'actual_kpis' => [],
            'performance_rating' => 'insufficient_data',
        ]);

        $this->assertNull($snapshot->updated_at);
        $this->assertNotNull($snapshot->created_at);
    }

    public function test_actual_kpis_casts_to_array(): void
    {
        $snapshot = CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'final', 'snapshotted_at' => now(),
            'channels_included' => ['email'],
            'actual_kpis' => ['total_reach' => 1000, 'best_channel' => 'email'],
            'performance_rating' => 'exceeded',
        ]);

        $this->assertIsArray($snapshot->actual_kpis);
        $this->assertEquals(1000, $snapshot->actual_kpis['total_reach']);
    }
}
