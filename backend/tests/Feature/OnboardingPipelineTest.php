<?php

namespace Tests\Feature;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\RecommendationApproved;
use App\Jobs\SyncIntegration;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\Connector;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * End-to-end test for the onboarding pipeline path.
 *
 * Covers SyncIntegration → ConnectorResult → ObservationRecorded →
 * ProcessObservation → Facts → DetectOpportunities → CommitDecision →
 * PrepareCampaign → GenerateContent → CreateRecommendation.
 *
 * ConnectorRegistry is swapped for a fake to avoid real HTTP calls.
 * Uses FakeAiProvider — no live API calls.
 * Relies on QUEUE_CONNECTION=sync (set in phpunit.xml).
 */
class OnboardingPipelineTest extends TestCase
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

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
            'industry' => 'auction',
            'website_url' => 'https://cbb-auctions.example.com',
        ]);

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

        // Blog channel mirrors what OnboardingController seeds for new companies.
        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'blog',
            'name' => 'Blog',
            'is_active' => true,
        ]);

        // Swap ConnectorRegistry with a fake that returns a pre-built crawl
        // result so no real HTTP requests are made.
        $fakeConnector = new class() implements Connector
        {
            public function supports(Integration $integration): bool
            {
                return true;
            }

            public function sync(Integration $integration): Collection
            {
                return collect([
                    new ConnectorResult(
                        sourceType: 'crawl',
                        sourceIdentifier: 'https://cbb-auctions.example.com',
                        payload: json_encode([
                            'url' => 'https://cbb-auctions.example.com',
                            'title' => 'CBB Auctions — Comic Book Marketplace',
                            'body_text' => 'We run weekly comic book auctions with rare Silver Age books. Browse thousands of CGC-graded comics.',
                        ], JSON_THROW_ON_ERROR),
                        observedAt: new DateTimeImmutable(),
                    ),
                ]);
            }
        };

        $this->app->instance(ConnectorRegistry::class, new ConnectorRegistry([$fakeConnector]));
    }

    public function test_full_onboarding_pipeline_produces_recommendation(): void
    {
        Event::fake([RecommendationApproved::class]);

        // Queue fixtures in pipeline order:
        // 1. ProcessObservation  → WebsiteAnalyst
        // 2. DetectOpportunities → OpportunityDetectionAnalyst
        // 3. CommitDecision      → RationaleGenerationAnalyst
        // 4. PrepareCampaign     → CampaignPreparationAnalyst
        // 5. GenerateContent     → ContentGenerationAnalyst (blog)
        $this->fake
            ->queueFixture('website-facts')
            ->queueFixture('opportunity-detection')
            ->queueFixture('rationale-generation')
            ->queueFixture('campaign-blueprint')
            ->queueFixture('blog-content');

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://cbb-auctions.example.com'],
            'status' => 'active',
        ]);

        // Dispatch as the OnboardingController does — synchronously in the same
        // request so the crawl is complete before the status page is loaded.
        SyncIntegration::dispatchSync($integration);

        // Integration should have updated sync timestamps.
        $integration->refresh();
        $this->assertNotNull($integration->last_run_at);
        $this->assertNotNull($integration->last_successful_run_at);
        $this->assertSame('active', $integration->status);

        // Observation should have been recorded and processed.
        $this->assertDatabaseHas('observations', [
            'company_id' => $this->company->id,
            'source_type' => 'crawl',
            'status' => 'processed',
        ]);

        // Facts must have been extracted.
        $factCount = Fact::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('is_current', true)
            ->count();

        $this->assertGreaterThan(0, $factCount, 'Expected at least one Fact after ProcessObservation.');

        // DigitalTwin should be active.
        $this->assertDatabaseHas('digital_twins', [
            'company_id' => $this->company->id,
            'status' => 'active',
        ]);

        // At least one Opportunity should have been detected.
        $this->assertGreaterThan(
            0,
            Opportunity::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'Expected at least one Opportunity after DetectOpportunities.',
        );

        // A pending Recommendation must exist — no auto-publishing in MVP.
        $recommendation = Recommendation::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', 'pending')
            ->first();

        $this->assertNotNull($recommendation, 'Expected a pending Recommendation after the full pipeline.');

        // All 5 AI steps must have been exercised.
        $this->assertSame(5, $this->fake->sentCount(),
            'Expected exactly 5 AI calls across the onboarding pipeline.');

        Event::assertNotDispatched(RecommendationApproved::class);
    }

    public function test_failed_crawl_marks_integration_as_error(): void
    {
        // Swap with a connector that throws during sync.
        $failingConnector = new class() implements Connector
        {
            public function supports(Integration $integration): bool
            {
                return true;
            }

            public function sync(Integration $integration): Collection
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $this->app->instance(ConnectorRegistry::class, new ConnectorRegistry([$failingConnector]));

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://cbb-auctions.example.com'],
            'status' => 'active',
        ]);

        // SyncIntegration::failed() calls markAsError() when the job fails.
        // With QUEUE_CONNECTION=sync, a thrown exception propagates through
        // the job's failed() hook before being re-thrown.
        try {
            SyncIntegration::dispatchSync($integration);
        } catch (\Throwable) {
            // Expected — the sync failure re-throws after marking error.
        }

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'status' => 'error',
        ]);

        // No observations should have been recorded.
        $this->assertDatabaseMissing('observations', ['company_id' => $this->company->id]);

        // No AI calls should have been made.
        $this->assertSame(0, $this->fake->sentCount());
    }
}
