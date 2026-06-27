<?php

namespace Tests\Feature\Learning;

use App\Models\Company;
use App\Models\Learning;
use App\Services\Learning\EvidenceEvaluator;
use App\Services\Learning\SignalTier;

class EvidenceEvaluatorTest extends LearningTestCase
{
    private EvidenceEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new EvidenceEvaluator();
    }

    public function test_discriminator_for_channel_signal(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email', 'rate' => 0.05]);

        $this->assertSame('email', $this->evaluator->discriminatorFor($learning));
    }

    public function test_discriminator_for_campaign_type_signal(): void
    {
        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);

        $this->assertSame('featured_item', $this->evaluator->discriminatorFor($learning));
    }

    public function test_discriminator_for_optimal_timing_uses_channel_type_key(): void
    {
        $learning = $this->makeLearning('optimal_timing_signal', ['channel_type' => 'email', 'published_hour' => 10]);

        $this->assertSame('email', $this->evaluator->discriminatorFor($learning));
    }

    public function test_discriminator_for_safety_signal_is_empty_string(): void
    {
        $learning = $this->makeLearning('email_deliverability_issue', ['hard_bounces' => 5]);

        $this->assertSame('', $this->evaluator->discriminatorFor($learning));
    }

    public function test_count_includes_applied_and_unapplied_within_90_days(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email'], null, 0);
        $this->makeLearning('channel_outperformed', ['channel' => 'email'], now()->toDateTimeString(), 30);

        $count = $this->evaluator->count($this->company->id, 'channel_outperformed', 'email');

        $this->assertSame(2, $count);
    }

    public function test_count_excludes_signals_older_than_90_days(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email'], null, 91);
        $this->makeLearning('channel_outperformed', ['channel' => 'email'], null, 0);

        $count = $this->evaluator->count($this->company->id, 'channel_outperformed', 'email');

        $this->assertSame(1, $count);
    }

    public function test_count_filters_by_discriminator(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'social']);

        $emailCount = $this->evaluator->count($this->company->id, 'channel_outperformed', 'email');
        $socialCount = $this->evaluator->count($this->company->id, 'channel_outperformed', 'social');

        $this->assertSame(1, $emailCount);
        $this->assertSame(1, $socialCount);
    }

    public function test_count_is_company_scoped(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other', 'slug' => 'other', 'industry' => 'test',
        ]);

        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        Learning::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'source_type' => 'execution_result',
            'source_id' => $this->campaign->id,
            'subject_type' => 'campaign',
            'subject_id' => $this->campaign->id,
            'signal' => 'channel_outperformed',
            'value' => ['channel' => 'email'],
            'applied_at' => null,
        ]);

        $count = $this->evaluator->count($this->company->id, 'channel_outperformed', 'email');

        $this->assertSame(1, $count);
    }

    public function test_meets_threshold_true_when_count_sufficient(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->assertTrue($this->evaluator->meetsThreshold($learning, 2, $this->company->id));
    }

    public function test_meets_threshold_false_when_count_insufficient(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->assertFalse($this->evaluator->meetsThreshold($learning, 2, $this->company->id));
    }

    public function test_safety_threshold_of_one_always_met(): void
    {
        $learning = $this->makeLearning('email_deliverability_issue', ['hard_bounces' => 5]);

        $this->assertTrue($this->evaluator->meetsThreshold($learning, SignalTier::SAFETY, $this->company->id));
    }
}
