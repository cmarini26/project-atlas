<?php

namespace Tests\Feature\Campaign;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Learning;
use App\Models\Opportunity;
use App\Services\Analyst\Content\ContentGenerationAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ContentGenerationAnalystLearningPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private ContentGenerationAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Campaign $campaign;

    private Channel $socialChannel;

    private Channel $emailChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->analyst = $this->app->make(ContentGenerationAnalyst::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
            'industry' => 'auction',
        ]);

        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
            'health_score' => 80,
        ]);

        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Main',
            'type' => 'inventory',
        ]);

        $this->socialChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'is_active' => true,
        ]);

        $this->emailChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);

        $blueprintData = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age Collection',
            'description' => 'High-value items',
            'relevance_score' => 85,
            'timing_score' => 80,
            'confidence_score' => 75,
            'urgency_score' => 70,
            'composite_score' => 79,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->socialChannel->id],
            'rationale' => ['why_now' => 'Auction closing soon.'],
            'expected_impact' => ['summary' => '15% lift'],
            'confidence_score' => 75,
            'status' => 'pending',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Silver Age Campaign',
            'blueprint' => $blueprintData,
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 0,
            'status' => 'draft',
        ]);
    }

    public function test_consistent_edit_patterns_are_included_in_generation_prompt(): void
    {
        $this->makeEditedApprovalLearning('instagram', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
            'price_inclusion' => 'added',
        ]);

        $this->makeEditedApprovalLearning('instagram', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
            'price_inclusion' => 'added',
        ]);

        $this->fake->queueFixture('social-content');

        $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain());

        $prompt = $this->fake->recorded()[0]['prompt'];
        $userPrompt = $prompt->user();

        $this->assertStringContainsString('Preference guidance:', $userPrompt);
        $this->assertStringContainsString('Keep the copy tighter and more concise', $userPrompt);
        $this->assertStringContainsString('Avoid hashtags unless they are essential.', $userPrompt);
        $this->assertStringContainsString('Include clear price or offer details', $userPrompt);
    }

    public function test_single_one_off_edit_does_not_overfit_future_generation(): void
    {
        $this->makeEditedApprovalLearning('instagram', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
        ]);

        $this->fake->queueFixture('social-content');

        $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain());

        $prompt = $this->fake->recorded()[0]['prompt'];

        $this->assertStringNotContainsString('Preference guidance:', $prompt->user());
    }

    public function test_learning_is_scoped_to_the_channel_where_edits_were_observed(): void
    {
        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'longer',
            'price_inclusion' => 'removed',
        ]);

        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'longer',
            'price_inclusion' => 'removed',
        ]);

        $this->fake->queueFixture('social-content');

        $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain());

        $prompt = $this->fake->recorded()[0]['prompt'];

        $this->assertStringNotContainsString('Preference guidance:', $prompt->user());
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function makeEditedApprovalLearning(string $channelType, array $patterns): void
    {
        Learning::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'approval',
            'source_id' => (string) str()->ulid(),
            'subject_type' => 'recommendation',
            'subject_id' => (string) str()->ulid(),
            'signal' => 'recommendation_edited_and_approved',
            'value' => [
                'campaign_type' => 'featured_item',
                'channel' => $channelType,
                'edit_patterns' => $patterns,
            ],
            'applied_at' => null,
        ]);
    }

    private function makeBrain(): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: new Collection(),
            catalog: $this->catalog,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
