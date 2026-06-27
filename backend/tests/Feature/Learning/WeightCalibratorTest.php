<?php

namespace Tests\Feature\Learning;

use App\Models\CompanyScoringWeights;
use App\Services\Learning\WeightCalibrator;
use Illuminate\Support\Facades\DB;

class WeightCalibratorTest extends LearningTestCase
{
    private WeightCalibrator $calibrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calibrator = new WeightCalibrator();
    }

    public function test_campaign_success_increases_type_modifier(): void
    {
        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);

        $effects = $this->calibrator->calibrate($learning, $this->company->id);

        $this->assertCount(1, $effects);
        $this->assertSame('weight_calibration', $effects[0]['type']);
        $this->assertSame('featured_item', $effects[0]['campaign_type']);
        $this->assertSame(1.0, $effects[0]['old_modifier']);
        $this->assertSame(1.05, $effects[0]['new_modifier']);

        $weights = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('is_current', true)
            ->first();
        $this->assertNotNull($weights);
        $this->assertSame(1.05, $weights->typeModifiers()['featured_item'] ?? null);
    }

    public function test_campaign_underperformance_decreases_type_modifier(): void
    {
        $learning = $this->makeLearning('campaign_type_underperformed', ['campaign_type' => 'featured_item']);

        $effects = $this->calibrator->calibrate($learning, $this->company->id);

        $this->assertSame(0.95, $effects[0]['new_modifier']);
    }

    public function test_versions_are_monotonically_increasing(): void
    {
        $l1 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $this->calibrator->calibrate($l1, $this->company->id);

        // Move past cooling period using raw query (created_at not in fillable)
        DB::table('company_scoring_weights')
            ->where('company_id', $this->company->id)
            ->update(['created_at' => now()->subDays(15)->toDateTimeString()]);

        $l2 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $this->calibrator->calibrate($l2, $this->company->id);

        $current = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('is_current', true)
            ->first();

        $this->assertSame(2, $current?->version);
    }

    public function test_cooling_period_prevents_rapid_recalibration(): void
    {
        $l1 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $this->calibrator->calibrate($l1, $this->company->id);

        // Second call within 14 days — should be blocked by cooling period
        $l2 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $effects = $this->calibrator->calibrate($l2, $this->company->id);

        $this->assertEmpty($effects);

        $count = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_modifier_cannot_exceed_maximum(): void
    {
        // Set current modifier past cooling period
        $w = CompanyScoringWeights::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'weights' => ['type_modifiers' => ['featured_item' => 1.48]],
            'version' => 1,
            'is_current' => true,
        ]);
        DB::table('company_scoring_weights')->where('id', $w->id)
            ->update(['created_at' => now()->subDays(15)->toDateTimeString()]);

        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $effects = $this->calibrator->calibrate($learning, $this->company->id);

        $this->assertSame(1.50, $effects[0]['new_modifier']);
    }

    public function test_modifier_cannot_go_below_minimum(): void
    {
        $w = CompanyScoringWeights::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'weights' => ['type_modifiers' => ['featured_item' => 0.52]],
            'version' => 1,
            'is_current' => true,
        ]);
        DB::table('company_scoring_weights')->where('id', $w->id)
            ->update(['created_at' => now()->subDays(15)->toDateTimeString()]);

        $learning = $this->makeLearning('campaign_type_underperformed', ['campaign_type' => 'featured_item']);
        $effects = $this->calibrator->calibrate($learning, $this->company->id);

        $this->assertSame(0.50, $effects[0]['new_modifier']);
    }

    public function test_previous_weights_row_is_retired(): void
    {
        $existing = CompanyScoringWeights::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'weights' => ['type_modifiers' => []],
            'version' => 1,
            'is_current' => true,
        ]);
        DB::table('company_scoring_weights')->where('id', $existing->id)
            ->update(['created_at' => now()->subDays(15)->toDateTimeString()]);

        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $this->calibrator->calibrate($learning, $this->company->id);

        $existing->refresh();
        $this->assertFalse((bool) $existing->is_current);
    }

    public function test_signals_without_campaign_type_return_empty(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $effects = $this->calibrator->calibrate($learning, $this->company->id);

        $this->assertEmpty($effects);
    }
}
