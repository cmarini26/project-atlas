<?php

namespace Tests\Feature\MarketingHealth;

use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\MarketingChannel;
use App\Models\MarketingHealthScore;
use App\Services\MarketingHealth\MarketingHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(MarketingHealthService::class);
    }

    private function makeCompany(): Company
    {
        return Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions-'.uniqid()]);
    }

    public function test_recompute_does_nothing_without_a_digital_twin(): void
    {
        $company = $this->makeCompany();

        $scores = $this->service->recompute($company);

        $this->assertCount(0, $scores);
        $this->assertDatabaseCount('marketing_health_scores', 0);
    }

    public function test_recompute_persists_a_score_per_scorable_dimension(): void
    {
        $company = $this->makeCompany();
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 80]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'display_name' => 'CBB Instagram',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
        ]);

        $scores = $this->service->recompute($company);

        $this->assertGreaterThanOrEqual(1, $scores->count());
        $this->assertTrue($scores->contains('dimension', 'presence_coverage'));
        $this->assertDatabaseHas('marketing_health_scores', [
            'company_id' => $company->id,
            'dimension' => 'presence_coverage',
            'is_current' => true,
        ]);
    }

    public function test_recompute_supersedes_the_prior_score_rather_than_duplicating(): void
    {
        $company = $this->makeCompany();
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 80]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'display_name' => 'CBB Instagram',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
        ]);

        $this->service->recompute($company);
        $first = MarketingHealthScore::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('dimension', 'presence_coverage')->first();

        $this->service->recompute($company);
        $second = MarketingHealthScore::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('dimension', 'presence_coverage')->current()->first();

        $first->refresh();

        $this->assertFalse($first->is_current);
        $this->assertSame($second->id, $first->superseded_by_id);
        $this->assertTrue($second->is_current);
        $this->assertSame(
            1,
            MarketingHealthScore::withoutGlobalScopes()->where('company_id', $company->id)->where('dimension', 'presence_coverage')->current()->count(),
        );
    }

    public function test_current_for_only_returns_current_rows(): void
    {
        $company = $this->makeCompany();
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 80]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'instagram', 'display_name' => 'CBB Instagram',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
        ]);

        $this->service->recompute($company);
        $this->service->recompute($company);

        $current = $this->service->currentFor($company);

        $this->assertSame(1, $current->where('dimension', 'presence_coverage')->count());
    }

    public function test_composite_for_is_null_without_any_scores(): void
    {
        $company = $this->makeCompany();

        $this->assertNull($this->service->compositeFor($company));
    }

    public function test_composite_for_is_confidence_weighted(): void
    {
        $company = $this->makeCompany();
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 80]);

        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'dimension' => 'website', 'score' => 100, 'confidence' => 100,
            'evidence' => [], 'computed_at' => now(), 'is_current' => true,
        ]);
        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'dimension' => 'social_activity', 'score' => 0, 'confidence' => 20,
            'evidence' => [], 'computed_at' => now(), 'is_current' => true,
        ]);

        $composite = $this->service->compositeFor($company);

        // (100*100 + 0*20) / (100+20) = 83.33 -> 83
        $this->assertSame(83, $composite['score']);
    }

    public function test_recompute_does_not_leak_scores_across_companies(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();

        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $companyA->id, 'status' => 'active', 'health_score' => 80]);
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $companyB->id, 'status' => 'active', 'health_score' => 80]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyA->id, 'type' => 'instagram', 'display_name' => 'A Instagram',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
        ]);

        $this->service->recompute($companyA);
        $this->service->recompute($companyB);

        $this->assertTrue($this->service->currentFor($companyA)->contains('dimension', 'presence_coverage'));
        $this->assertFalse($this->service->currentFor($companyB)->contains('dimension', 'presence_coverage'));
    }

    public function test_fact_based_recompute_reflects_new_evidence(): void
    {
        $company = $this->makeCompany();
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'active', 'health_score' => 80]);

        Fact::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'key' => 'instagram.posting_cadence', 'value' => json_encode(2.0),
            'data_type' => 'float', 'confidence' => 90, 'is_current' => true, 'valid_from' => now(),
        ]);

        $this->service->recompute($company);

        $score = MarketingHealthScore::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('dimension', 'social_activity')->current()->first();

        $this->assertNotNull($score);
        $this->assertSame(100, $score->score);
        $this->assertNotEmpty($score->evidence);
    }
}
