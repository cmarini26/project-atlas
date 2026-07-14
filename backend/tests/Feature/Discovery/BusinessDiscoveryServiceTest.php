<?php

namespace Tests\Feature\Discovery;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Enums\DiscoveryAttemptStatus;
use App\Enums\DiscoveryStage;
use App\Enums\MarketingChannelType;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\DiscoveryConnectorAttempt;
use App\Models\DiscoveryRun;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Knowledge;
use App\Models\MarketingChannel;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\Discovery\BusinessDiscoveryService;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\AutoDiscoverableConnector;
use App\Services\Observatory\Connectors\Contracts\Connector;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Milestone 15 Phase 2 — Business Discovery Orchestration. Exercises
 * BusinessDiscoveryService against fake connectors (no real HTTP), but the
 * REAL Observation → Fact → Knowledge → Opportunity → Recommendation
 * pipeline, proving Discovery orchestrates it without replacing or
 * duplicating it. QUEUE_CONNECTION=sync (phpunit.xml) runs every dispatched
 * job inline, so the whole chain completes within a single start() call.
 */
class BusinessDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $ai;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->ai);

        $this->company = Company::factory()->create();

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

        // A blog Channel is what GenerateContent/CreateRecommendation publish
        // draft content against — mirrors OnboardingController's own seeding
        // for a new company (unrelated to Business Discovery itself).
        Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'blog',
            'name' => 'Blog',
            'is_active' => true,
        ]);
    }

    private function queueFullPipelineFixtures(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->ai
                ->queueFixture('website-facts')
                ->queueFixture('opportunity-detection')
                ->queueFixture('rationale-generation')
                ->queueFixture('campaign-blueprint')
                ->queueFixture('blog-content');
        }
    }

    private function bindRegistry(Connector ...$connectors): void
    {
        $this->app->instance(ConnectorRegistry::class, new ConnectorRegistry($connectors));
    }

    private function fakeAutoDiscoverableConnector(
        MarketingChannelType $type,
        string $connectorType,
        bool $shouldFail = false,
    ): AutoDiscoverableConnector {
        return new class($type, $connectorType, $shouldFail) implements AutoDiscoverableConnector
        {
            public function __construct(
                private readonly MarketingChannelType $type,
                private readonly string $connectorType,
                private readonly bool $shouldFail,
            ) {}

            public function supports(Integration $integration): bool
            {
                return $integration->type === $this->connectorType;
            }

            public function marketingChannelType(): MarketingChannelType
            {
                return $this->type;
            }

            public function connectorType(): string
            {
                return $this->connectorType;
            }

            /** @return array<string, mixed> */
            public function buildIntegrationConfig(MarketingChannel $channel): array
            {
                return ['url' => $channel->handle_or_url];
            }

            /** @return Collection<int, ConnectorResult> */
            public function sync(Integration $integration): Collection
            {
                if ($this->shouldFail) {
                    throw new RuntimeException('Connection refused');
                }

                return collect([
                    new ConnectorResult(
                        sourceType: 'crawl',
                        sourceIdentifier: (string) ($integration->config['url'] ?? $this->connectorType),
                        payload: json_encode([
                            'url' => $integration->config['url'] ?? 'https://example.com',
                            'title' => 'Example',
                            'body_text' => 'We sell handmade goods and run weekly promotions in our shop.',
                        ], JSON_THROW_ON_ERROR),
                        observedAt: new DateTimeImmutable(),
                    ),
                ]);
            }
        };
    }

    // ── Website only ────────────────────────────────────────────────────────

    public function test_discovery_with_website_only_produces_a_recommendation(): void
    {
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));
        $this->queueFullPipelineFixtures();

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(1, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());
        $this->assertDatabaseHas('discovery_connector_attempts', [
            'discovery_run_id' => $run->id,
            'status' => DiscoveryAttemptStatus::Succeeded->value,
        ]);

        $this->assertGreaterThan(0, Fact::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertNotNull(
            Recommendation::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', 'pending')->first(),
        );

        $this->assertSame(DiscoveryStage::Completed, $run->fresh()->stage);
    }

    // ── Website + already-connected Instagram-equivalent ────────────────────

    public function test_discovery_with_website_and_an_already_connected_asset_reuses_its_integration(): void
    {
        // Each declared asset's crawl produces facts but deliberately no
        // opportunity — keeps this test focused on orchestration (attempt
        // count, integration reuse), not on re-running the full downstream
        // pipeline twice (already proven end-to-end by the "website only"
        // and "progress_for" tests below).
        $this->ai->queueFixture('website-facts')->queueResponse('{"opportunities": []}');
        $this->ai->queueFixture('website-facts')->queueResponse('{"opportunities": []}');

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);

        // Simulates Instagram already connected for real via Settings —
        // no AutoDiscoverableConnector registered for it, yet Discovery must
        // still resync it because it's already linked to a connected Integration.
        $existingIntegration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token'],
            'status' => 'active',
        ]);

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Instagram,
            'integration_id' => $existingIntegration->id,
            'is_connected' => true,
        ]);

        $this->bindRegistry(
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'),
            $this->reuseCapableConnector('instagram'),
        );

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(2, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());
        $this->assertSame(
            2,
            DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)
                ->where('status', DiscoveryAttemptStatus::Succeeded->value)
                ->count(),
        );

        // The reused Integration is the exact same row — never duplicated.
        $this->assertSame(1, Integration::withoutGlobalScopes()->where('company_id', $this->company->id)->where('type', 'instagram')->count());
    }

    // ── One connector fails, another succeeds ───────────────────────────────

    public function test_one_failed_connector_never_fails_the_whole_run(): void
    {
        $this->queueFullPipelineFixtures();

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://unreachable.example.com',
        ]);

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::GoogleBusinessProfile,
            'handle_or_url' => 'Acme Comics',
        ]);

        $this->bindRegistry(
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl', shouldFail: true),
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::GoogleBusinessProfile, 'api'),
        );

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(2, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());

        $failed = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->where('connector_type', 'website_crawl')->first();
        $this->assertSame(DiscoveryAttemptStatus::Failed, $failed->status);
        $this->assertNotNull($failed->error_message);

        $succeeded = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->where('connector_type', 'api')->first();
        $this->assertSame(DiscoveryAttemptStatus::Succeeded, $succeeded->status);

        // The company still gets a recommendation from the successful connector.
        $this->assertNotNull(
            Recommendation::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', 'pending')->first(),
        );
        $this->assertSame(DiscoveryStage::Completed, $run->fresh()->stage);

        // The unreachable website did NOT flip the whole run to an error state.
        $this->assertNotSame(DiscoveryStage::CompletedWithErrors, $run->fresh()->stage);
    }

    public function test_every_connector_failing_reaches_completed_with_errors(): void
    {
        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://unreachable.example.com',
        ]);

        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl', shouldFail: true));

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(DiscoveryStage::CompletedWithErrors, $run->fresh()->stage);
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('recommendations', 0);
    }

    // ── Unsupported assets ───────────────────────────────────────────────────

    public function test_declared_assets_with_no_connector_get_no_attempt_row(): void
    {
        MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Facebook]);
        MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::LinkedIn]);

        $this->bindRegistry(); // no connectors registered at all

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(0, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());

        $progress = app(BusinessDiscoveryService::class)->progressFor($this->company);
        $this->assertNotNull($progress);
        foreach ($progress['connectors'] as $connector) {
            $this->assertSame('not_attempted', $connector['status']);
        }
    }

    public function test_unsupported_assets_do_not_block_completion_alongside_a_successful_one(): void
    {
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));
        $this->queueFullPipelineFixtures();

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);
        MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Facebook]);
        MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::LinkedIn]);

        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $this->assertSame(DiscoveryStage::Completed, $run->fresh()->stage);
        $this->assertNotNull(
            Recommendation::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', 'pending')->first(),
        );

        $progress = app(BusinessDiscoveryService::class)->progressFor($this->company);
        $statusesByType = collect($progress['connectors'])->pluck('status', 'type');
        $this->assertSame('succeeded', $statusesByType['website']);
        $this->assertSame('not_attempted', $statusesByType['facebook']);
        $this->assertSame('not_attempted', $statusesByType['linkedin']);
    }

    // ── Retry / recovery ─────────────────────────────────────────────────────

    public function test_retry_reuses_the_same_run_and_only_retries_the_failed_attempt(): void
    {
        // First pass: website fails.
        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://unreachable.example.com',
        ]);

        $failingConnector = $this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl', shouldFail: true);
        $this->bindRegistry($failingConnector);

        $run = app(BusinessDiscoveryService::class)->start($this->company);
        $this->assertSame(DiscoveryStage::CompletedWithErrors, $run->fresh()->stage);
        $this->assertSame(1, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());

        // The site is fixed — swap in a connector that now succeeds for the
        // exact same connector type, and retry the SAME run.
        $this->queueFullPipelineFixtures();
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));

        $retried = app(BusinessDiscoveryService::class)->retry($run->fresh());

        $this->assertSame($run->id, $retried->id, 'Retry must reuse the existing DiscoveryRun, never create a new one.');
        $this->assertSame(1, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count(), 'Retry must reuse the existing attempt row, never duplicate it.');
        $this->assertSame(DiscoveryStage::Completed, $retried->stage);
        $this->assertSame(1, DiscoveryRun::where('company_id', $this->company->id)->count(), 'No new DiscoveryRun was created by retry().');
    }

    public function test_retry_never_duplicates_integrations_or_observations(): void
    {
        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://unreachable.example.com',
        ]);

        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl', shouldFail: true));
        $run = app(BusinessDiscoveryService::class)->start($this->company);

        $integrationCountBefore = Integration::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        $this->assertSame(1, $integrationCountBefore);

        $this->queueFullPipelineFixtures();
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));
        app(BusinessDiscoveryService::class)->retry($run->fresh());

        $this->assertSame(
            $integrationCountBefore,
            Integration::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'Retry must never create a second Integration for the same asset.',
        );
        $this->assertSame(1, Observation::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, MarketingChannel::where('company_id', $this->company->id)->count());
    }

    public function test_retry_preserves_a_successful_attempt_while_retrying_a_failed_one(): void
    {
        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);
        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::GoogleBusinessProfile,
            'handle_or_url' => 'Acme Comics',
        ]);

        $this->queueFullPipelineFixtures();
        $this->bindRegistry(
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'),
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::GoogleBusinessProfile, 'api', shouldFail: true),
        );

        $run = app(BusinessDiscoveryService::class)->start($this->company);
        $websiteAttempt = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->where('connector_type', 'website_crawl')->first();
        $this->assertSame(DiscoveryAttemptStatus::Succeeded, $websiteAttempt->status);
        $succeededAt = $websiteAttempt->completed_at;

        // Retry — Google Business now succeeds too (its own full pipeline
        // run needs its own queued AI responses), but the website attempt
        // must never be re-touched or re-run.
        $this->queueFullPipelineFixtures();
        $this->bindRegistry(
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'),
            $this->fakeAutoDiscoverableConnector(MarketingChannelType::GoogleBusinessProfile, 'api'),
        );
        app(BusinessDiscoveryService::class)->retry($run->fresh());

        $websiteAttemptCountAfterFirstRun = $websiteAttempt->attempt_count;
        $websiteAttempt->refresh();
        $this->assertSame(DiscoveryAttemptStatus::Succeeded, $websiteAttempt->status);
        $this->assertSame($websiteAttemptCountAfterFirstRun, $websiteAttempt->attempt_count, 'A preserved successful attempt must never be re-dispatched.');
        $this->assertEquals($succeededAt, $websiteAttempt->completed_at);

        $googleAttempt = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->where('connector_type', 'api')->first();
        $this->assertSame(DiscoveryAttemptStatus::Succeeded, $googleAttempt->status);
        $this->assertSame(2, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count(), 'No duplicate attempt rows from retry.');
    }

    public function test_retry_picks_up_an_asset_that_only_became_connected_after_the_original_run(): void
    {
        MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Instagram]);

        $this->bindRegistry(); // Instagram not yet connected — no connector at all
        $run = app(BusinessDiscoveryService::class)->start($this->company);
        $this->assertSame(0, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());
        $this->assertSame(DiscoveryStage::CompletedWithErrors, $run->fresh()->stage);

        // The user connects Instagram for real from Settings in between.
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'Instagram',
            'config' => ['access_token' => 'token'], 'status' => 'active',
        ]);
        MarketingChannel::where('company_id', $this->company->id)->where('type', 'instagram')->first()
            ->update(['integration_id' => $integration->id, 'is_connected' => true]);

        $this->ai->queueFixture('website-facts')->queueResponse('{"opportunities": []}');
        $this->bindRegistry($this->reuseCapableConnector('instagram'));

        $retried = app(BusinessDiscoveryService::class)->retry($run->fresh());

        $this->assertSame(1, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());
        $this->assertSame(
            DiscoveryAttemptStatus::Succeeded,
            DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->first()->status,
        );
        $this->assertNotSame(DiscoveryStage::CompletedWithErrors, $retried->stage);
    }

    // ── No-opportunities terminal state ──────────────────────────────────────

    public function test_reaches_completed_no_opportunities_when_nothing_to_act_on(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()->subMinutes(3)]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => '{}', 'status' => 'processed', 'observed_at' => now()->subMinutes(3),
            'processed_at' => now()->subMinutes(2),
        ]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id, 'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id, 'integration_id' => $integration->id,
            'connector_type' => 'website_crawl', 'status' => DiscoveryAttemptStatus::Succeeded,
            'attempt_count' => 1, 'observation_id' => $observation->id, 'completed_at' => now()->subMinutes(2),
        ]);

        DigitalTwin::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['status' => 'active']);
        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'context', 'subject' => 'business',
            'body' => 'Acme sells things.', 'confidence' => 0.8, 'is_active' => true,
            'generated_at' => now()->subMinutes(2),
        ]);

        // No Opportunity, no Recommendation — facts_created still reported.
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'observation_id' => $observation->id,
            'key' => 'business.name', 'value' => 'Acme', 'data_type' => 'string',
            'confidence' => 90, 'is_current' => true, 'valid_from' => now()->subMinutes(2),
        ]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::CompletedNoOpportunities, $refreshed->stage);
        $this->assertNotNull($refreshed->completed_at);

        $progress = app(BusinessDiscoveryService::class)->progressFor($this->company);
        $this->assertSame('completed_no_opportunities', $progress['stage']);
        $this->assertSame(1, $progress['facts_created']);
        $this->assertSame(0, $progress['recommendation_count']);
        $this->assertTrue($progress['retry_available']);
    }

    public function test_stays_recommending_within_the_grace_period_after_processing(): void
    {
        // Same shape as the no-opportunities test, but processed just now —
        // must not prematurely declare "no opportunities" while an async
        // downstream step could still be in flight.
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => '{}', 'status' => 'processed', 'observed_at' => now(), 'processed_at' => now(),
        ]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id, 'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id, 'integration_id' => $integration->id,
            'connector_type' => 'website_crawl', 'status' => DiscoveryAttemptStatus::Succeeded,
            'attempt_count' => 1, 'observation_id' => $observation->id, 'completed_at' => now(),
        ]);

        DigitalTwin::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['status' => 'active']);
        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'context', 'subject' => 'business',
            'body' => 'Acme sells things.', 'confidence' => 0.8, 'is_active' => true, 'generated_at' => now(),
        ]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::Recommending, $refreshed->stage);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_retry_never_touches_another_companys_discovery_run(): void
    {
        $otherCompany = Company::factory()->create();

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website, 'handle_or_url' => 'https://a.example.com',
        ]);
        MarketingChannel::factory()->for($otherCompany)->create([
            'type' => MarketingChannelType::Website, 'handle_or_url' => 'https://b.example.com',
        ]);

        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl', shouldFail: true));

        $runA = app(BusinessDiscoveryService::class)->start($this->company);
        $runB = app(BusinessDiscoveryService::class)->start($otherCompany);

        $integrationB = Integration::withoutGlobalScopes()->where('company_id', $otherCompany->id)->first();
        $attemptB = DiscoveryConnectorAttempt::where('discovery_run_id', $runB->id)->first();

        $this->queueFullPipelineFixtures();
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));

        app(BusinessDiscoveryService::class)->retry($runA->fresh());

        // Company A's retry must never touch company B's run, attempt, or integration.
        $this->assertSame(DiscoveryStage::CompletedWithErrors, $runB->fresh()->stage);
        $this->assertSame(DiscoveryAttemptStatus::Failed, $attemptB->fresh()->status);
        $this->assertSame('error', $integrationB->fresh()->status);
        $this->assertSame(0, Recommendation::withoutGlobalScopes()->where('company_id', $otherCompany->id)->count());
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_running_discovery_twice_reuses_the_same_integration_and_never_duplicates_assets(): void
    {
        $this->queueFullPipelineFixtures(2);

        $channel = MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);

        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));

        $firstRun = app(BusinessDiscoveryService::class)->start($this->company);
        $channel->refresh();
        $firstIntegrationId = $channel->integration_id;
        $this->assertNotNull($firstIntegrationId);

        $secondRun = app(BusinessDiscoveryService::class)->start($this->company);
        $channel->refresh();

        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame($firstIntegrationId, $channel->integration_id, 'Discovery must reuse the same Integration, never create a second one.');
        $this->assertSame(1, MarketingChannel::where('company_id', $this->company->id)->count(), 'No duplicate MarketingChannel rows.');
        $this->assertSame(1, Integration::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'No duplicate Integration rows.');
        $this->assertSame(2, DiscoveryRun::where('company_id', $this->company->id)->count());
    }

    // ── Progress state transitions (direct, deterministic) ──────────────────

    public function test_stage_is_discovering_while_no_attempt_has_succeeded_yet(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id,
            'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id,
            'connector_type' => 'website_crawl',
            'status' => DiscoveryAttemptStatus::Running,
            'attempt_count' => 1,
        ]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::Discovering, $refreshed->stage);
        $this->assertNull($refreshed->completed_at);
    }

    public function test_stage_is_analyzing_once_succeeded_but_observation_not_yet_processed(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => '{}', 'status' => 'processing', 'observed_at' => now(),
        ]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id, 'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id, 'integration_id' => $integration->id,
            'connector_type' => 'website_crawl', 'status' => DiscoveryAttemptStatus::Succeeded,
            'attempt_count' => 1, 'observation_id' => $observation->id,
        ]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::Analyzing, $refreshed->stage);
    }

    public function test_stage_is_understanding_once_processed_but_brain_not_yet_synthesized(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => '{}', 'status' => 'processed', 'observed_at' => now(), 'processed_at' => now(),
        ]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id, 'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id, 'integration_id' => $integration->id,
            'connector_type' => 'website_crawl', 'status' => DiscoveryAttemptStatus::Succeeded,
            'attempt_count' => 1, 'observation_id' => $observation->id,
        ]);

        // DigitalTwin still 'initializing', no Knowledge yet.
        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::Understanding, $refreshed->stage);
    }

    public function test_stage_is_recommending_once_understood_but_no_recommendation_yet(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($this->company)->create(['type' => MarketingChannelType::Website]);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => '{}', 'status' => 'processed', 'observed_at' => now(), 'processed_at' => now(),
        ]);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id, 'company_id' => $this->company->id,
            'marketing_channel_id' => $channel->id, 'integration_id' => $integration->id,
            'connector_type' => 'website_crawl', 'status' => DiscoveryAttemptStatus::Succeeded,
            'attempt_count' => 1, 'observation_id' => $observation->id,
        ]);

        DigitalTwin::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['status' => 'active']);
        Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'context', 'subject' => 'business',
            'body' => 'Acme sells things.', 'confidence' => 0.8, 'is_active' => true, 'generated_at' => now(),
        ]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::Recommending, $refreshed->stage);
    }

    public function test_stage_is_completed_with_errors_when_no_asset_was_ever_observable(): void
    {
        $run = DiscoveryRun::create(['company_id' => $this->company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);

        $refreshed = app(BusinessDiscoveryService::class)->refreshStage($run);

        $this->assertSame(DiscoveryStage::CompletedWithErrors, $refreshed->stage);
        $this->assertNotNull($refreshed->completed_at);
    }

    // ── Summary generation ───────────────────────────────────────────────────

    public function test_progress_for_reports_real_persisted_counts(): void
    {
        $this->bindRegistry($this->fakeAutoDiscoverableConnector(MarketingChannelType::Website, 'website_crawl'));
        $this->queueFullPipelineFixtures();

        MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
        ]);

        app(BusinessDiscoveryService::class)->start($this->company);

        $progress = app(BusinessDiscoveryService::class)->progressFor($this->company);

        $this->assertNotNull($progress);
        $this->assertSame('completed', $progress['stage']);
        $this->assertGreaterThan(0, $progress['facts_created']);
        $this->assertGreaterThan(0, $progress['opportunities_found']);
        $this->assertGreaterThan(0, $progress['recommendations_generated']);
        $this->assertSame(1, $progress['recommendation_count']);
        $this->assertNotNull($progress['first_recommendation_id']);

        $websiteConnector = collect($progress['connectors'])->firstWhere('type', 'website');
        $this->assertSame('succeeded', $websiteConnector['status']);
    }

    public function test_progress_for_returns_null_when_discovery_never_started(): void
    {
        $this->assertNull(app(BusinessDiscoveryService::class)->progressFor($this->company));
    }

    /** A plain (non-auto-discoverable) Connector standing in for a real, already-connected source. */
    private function reuseCapableConnector(string $integrationType): Connector
    {
        return new class($integrationType) implements Connector
        {
            public function __construct(private readonly string $integrationType) {}

            public function supports(Integration $integration): bool
            {
                return $integration->type === $this->integrationType;
            }

            /** @return Collection<int, ConnectorResult> */
            public function sync(Integration $integration): Collection
            {
                return collect([
                    new ConnectorResult(
                        sourceType: 'crawl',
                        sourceIdentifier: $this->integrationType,
                        payload: json_encode([
                            'url' => 'https://instagram.example.com/acme',
                            'title' => 'Acme on Instagram',
                            'body_text' => 'Behind the scenes photos of our handmade goods workshop.',
                        ], JSON_THROW_ON_ERROR),
                        observedAt: new DateTimeImmutable(),
                    ),
                ]);
            }
        };
    }
}
