<?php

namespace Tests\Feature;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\ObservationRecorded;
use App\Events\RecommendationApproved;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * End-to-end smoke test for the Atlas pipeline.
 *
 * Exercises the full path from Observation → ProcessObservation →
 * Facts + Knowledge → DetectOpportunities → CommitDecision →
 * PrepareCampaign → GenerateContent → CreateRecommendation.
 *
 * Uses FakeAiProvider — no live API calls are made.
 * Relies on QUEUE_CONNECTION=sync (set in phpunit.xml) so every
 * dispatched job runs inline, allowing the pipeline to cascade fully
 * from a single event dispatch.
 */
class PipelineSmokeTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private Company $company;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);

        // Build minimal but complete company context.
        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Atlas Smoke Test Co',
            'slug' => 'atlas-smoke',
            'industry' => 'auction',
        ]);

        // Twin starts as 'initializing' — ProcessObservation activates it.
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'initializing',
            'health_score' => 0,
        ]);

        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Main Catalog',
            'type' => 'inventory',
        ]);

        // One active email channel — PrepareCampaign dispatches GenerateContent per channel.
        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);
    }

    public function test_full_pipeline_produces_recommendation_from_observation(): void
    {
        // Stop the pipeline at Recommendation — no publishing in MVP.
        Event::fake([RecommendationApproved::class]);

        // Queue fixtures in the exact order the pipeline will consume them:
        // 1. ProcessObservation  → WebsiteAnalyst                (website-facts)
        // 2. DetectOpportunities → OpportunityDetectionAnalyst   (opportunity-detection)
        // 3. CommitDecision      → RationaleGenerationAnalyst    (rationale-generation)
        // 4. PrepareCampaign     → CampaignPreparationAnalyst    (campaign-blueprint)
        // 5. GenerateContent     → ContentGenerationAnalyst(email)(email-content)
        $this->fake
            ->queueFixture('website-facts')
            ->queueFixture('opportunity-detection')
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        // Create integration and observation.
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://atlas-smoke-test.example.com'],
            'status' => 'active',
        ]);

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://atlas-smoke-test.example.com',
            'raw_payload' => json_encode([
                'url' => 'https://atlas-smoke-test.example.com',
                'title' => 'Atlas Smoke Test — Comic Auctions',
                'bodyText' => 'We run weekly comic book auctions with rare Silver Age books.',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        // Fire the event that starts the pipeline.
        // QUEUE_CONNECTION=sync means every dispatched job runs inline, so
        // the full cascade completes before this call returns.
        ObservationRecorded::dispatch($observation);

        // --- Assert intermediate state ---

        // Observation should be processed (not pending/failed).
        $this->assertDatabaseHas('observations', [
            'id' => $observation->id,
            'status' => 'processed',
        ]);

        // Facts should have been extracted.
        $this->assertGreaterThan(
            0,
            Fact::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('is_current', true)
                ->count(),
            'Expected at least one current Fact after ProcessObservation.',
        );

        // DigitalTwin should have been activated.
        $this->assertDatabaseHas('digital_twins', [
            'company_id' => $this->company->id,
            'status' => 'active',
        ]);

        // An Opportunity should have been detected.
        $this->assertGreaterThan(
            0,
            Opportunity::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->count(),
            'Expected at least one Opportunity after DetectOpportunities.',
        );

        // A Decision should have been committed.
        $decision = Decision::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($decision, 'Expected a Decision to be committed.');
        $this->assertNotEmpty($decision->rationale, 'Decision rationale must not be empty.');
        $this->assertArrayHasKey('why_now', $decision->rationale);
        $this->assertArrayHasKey('why_this', $decision->rationale);
        $this->assertArrayHasKey('why_channel', $decision->rationale);
        $this->assertArrayHasKey('why_works', $decision->rationale);

        // --- Assert final state ---

        // A Recommendation must exist and be pending human approval.
        $recommendation = Recommendation::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($recommendation, 'Expected a Recommendation to be created.');
        $this->assertSame('pending', $recommendation->status,
            'Recommendation must be pending — no auto-publishing in MVP.');

        // Decision should be in 'recommended' status.
        $this->assertDatabaseHas('decisions', [
            'id' => $decision->id,
            'status' => 'recommended',
        ]);

        // All AI fixtures must have been consumed (proves every step called the AI).
        $this->assertSame(5, $this->fake->sentCount(),
            'Expected exactly 5 AI calls across the full pipeline.');

        // Confirm no publishing happened.
        Event::assertNotDispatched(RecommendationApproved::class);
    }

    public function test_pipeline_does_not_publish_without_approval(): void
    {
        // Same as the full test but focuses on the publishing gate.
        Event::fake([RecommendationApproved::class]);

        $this->fake
            ->queueFixture('website-facts')
            ->queueFixture('opportunity-detection')
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('email-content');

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://atlas-smoke-test.example.com'],
            'status' => 'active',
        ]);

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://atlas-smoke-test.example.com',
            'raw_payload' => json_encode([
                'url' => 'https://atlas-smoke-test.example.com',
                'title' => 'Atlas Smoke Test',
                'bodyText' => 'Test content.',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        ObservationRecorded::dispatch($observation);

        // No published content should exist.
        $this->assertDatabaseMissing('content_assets', [
            'company_id' => $this->company->id,
            'status' => 'published',
        ]);
    }
}
