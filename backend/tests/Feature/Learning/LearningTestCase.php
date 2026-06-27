<?php

namespace Tests\Feature\Learning;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Learning;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class LearningTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Channel $channel;

    protected Campaign $campaign;

    protected Opportunity $opportunity;

    protected Decision $decision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co', 'industry' => 'test',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $this->opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'subject_type' => 'company',
            'type' => 'featured_item', 'title' => 'Test', 'description' => 'Desc',
            'relevance_score' => 80, 'timing_score' => 80,
            'confidence_score' => 80, 'urgency_score' => 80, 'composite_score' => 80,
            'status' => 'selected', 'detected_at' => now(),
        ]);

        $this->decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $this->opportunity->id,
            'campaign_type' => 'featured_item', 'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now'],
            'expected_impact' => ['target_engagement_rate' => 0.05],
            'confidence_score' => 70, 'status' => 'recommended', 'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $this->decision->id,
            'campaign_type' => 'featured_item', 'title' => 'Test Campaign',
            'blueprint' => ['channel_strategy' => [['channel' => 'email', 'angle' => 'urgency']]],
            'blueprint_version' => '1.0', 'prompt_version' => '1.0',
            'expected_asset_count' => 1, 'generated_asset_count' => 1, 'status' => 'published',
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function makeLearning(
        string $signal,
        array $value,
        ?string $appliedAt = null,
        int $daysAgo = 0,
    ): Learning {
        $learning = Learning::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'execution_result',
            'source_id' => $this->campaign->id,
            'subject_type' => 'campaign',
            'subject_id' => $this->campaign->id,
            'signal' => $signal,
            'value' => $value,
            'applied_at' => $appliedAt,
        ]);

        if ($daysAgo > 0) {
            $ts = now()->subDays($daysAgo)->toDateTimeString();
            DB::table('learnings')->where('id', $learning->id)->update([
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $learning->refresh();
        }

        return $learning;
    }
}
