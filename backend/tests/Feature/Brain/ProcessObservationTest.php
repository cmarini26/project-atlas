<?php

namespace Tests\Feature\Brain;

use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiProviderOverloadedException;
use App\AI\Testing\FakeAiProvider;
use App\Events\DigitalTwinActivated;
use App\Events\ObservationProcessed;
use App\Jobs\DetectOpportunities;
use App\Jobs\ProcessObservation;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Integration;
use App\Models\Observation;
use App\Models\User;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessObservationTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private Company $company;

    private Observation $observation;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent DigitalTwinActivated from cascading into DetectOpportunities in unit tests
        // that only provision fixtures for the observation/knowledge phase.
        Event::fake([DigitalTwinActivated::class]);

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'mixed',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'initializing',
        ]);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://cbbauctions.com'],
            'status' => 'active',
        ]);

        $this->observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => json_encode([
                'url' => 'https://cbbauctions.com',
                'title' => 'CBB Auctions',
                'body_text' => 'We are a comic book auction house.',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_marks_observation_processed(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->observation->refresh();
        $this->assertEquals('processed', $this->observation->status);
        $this->assertNotNull($this->observation->processed_at);
    }

    public function test_creates_facts_from_ai_response(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->assertDatabaseCount('facts', 4);
        $this->assertDatabaseHas('facts', [
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'is_current' => true,
        ]);
    }

    public function test_creates_knowledge_entries(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->assertDatabaseHas('knowledge_entries', [
            'company_id' => $this->company->id,
            'subject' => 'business',
            'is_active' => true,
        ]);
    }

    public function test_activates_digital_twin(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertEquals('active', $twin->status);
    }

    public function test_fires_observation_processed_event(): void
    {
        Event::fake([ObservationProcessed::class, DigitalTwinActivated::class]);
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        Event::assertDispatched(ObservationProcessed::class);
    }

    public function test_marks_failed_when_ai_returns_empty_facts(): void
    {
        $this->fake->queueResponse('{"facts": []}');

        try {
            (new ProcessObservation($this->observation))->handle(
                $this->app->make(AnalystRegistry::class),
                $this->app->make(FactService::class),
                $this->app->make(KnowledgeService::class),
            );
            $this->fail('Expected FactExtractionFailedException was not thrown.');
        } catch (FactExtractionFailedException) {
            // expected
        }

        $this->observation->refresh();
        $this->assertEquals('failed', $this->observation->status);
        $this->assertDatabaseCount('facts', 0);
    }

    public function test_marks_failed_when_ai_returns_invalid_json(): void
    {
        $this->fake->queueResponse('not-json');

        try {
            (new ProcessObservation($this->observation))->handle(
                $this->app->make(AnalystRegistry::class),
                $this->app->make(FactService::class),
                $this->app->make(KnowledgeService::class),
            );
            $this->fail('Expected FactExtractionFailedException was not thrown.');
        } catch (FactExtractionFailedException) {
            // expected
        }

        $this->observation->refresh();
        $this->assertEquals('failed', $this->observation->status);
    }

    public function test_failed_ai_analysis_surfaces_as_ai_failed_in_onboarding_status(): void
    {
        $this->fake->queueResponse('{"facts": []}');

        try {
            (new ProcessObservation($this->observation))->handle(
                $this->app->make(AnalystRegistry::class),
                $this->app->make(FactService::class),
                $this->app->make(KnowledgeService::class),
            );
        } catch (FactExtractionFailedException) {
            // expected
        }

        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@cbbauctions.com',
            'password' => 'secret-password',
        ]);

        CompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->getJson('/api/onboarding/status');

        $response->assertOk()
            ->assertJson([
                'crawl_succeeded' => true,
                'ai_failed' => true,
                'fact_count' => 0,
            ]);
    }

    public function test_dispatches_opportunity_detection_after_processing(): void
    {
        Bus::fake([DetectOpportunities::class]);
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        Bus::assertDispatched(
            DetectOpportunities::class,
            fn (DetectOpportunities $job): bool => $job->company->id === $this->company->id,
        );
    }

    public function test_downstream_failure_does_not_mark_observation_failed(): void
    {
        // Only the fact-extraction fixture is queued; the opportunity scan
        // triggered by ObservationProcessed hits an empty FakeAiProvider queue
        // and throws. That downstream failure is contained — the observation
        // itself was processed successfully and must stay that way.
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->observation->refresh();
        $this->assertEquals('processed', $this->observation->status);
        $this->assertDatabaseCount('facts', 4);
    }

    public function test_marks_observation_retrying_when_provider_overloaded(): void
    {
        $this->fake->queueException(
            new AiProviderOverloadedException('Anthropic API is overloaded', requestId: 'req_test')
        );

        try {
            (new ProcessObservation($this->observation))->handle(
                $this->app->make(AnalystRegistry::class),
                $this->app->make(FactService::class),
                $this->app->make(KnowledgeService::class),
            );
            $this->fail('Expected AiProviderOverloadedException was not thrown.');
        } catch (AiProviderOverloadedException) {
            // expected — rethrown so queued workers can retry
        }

        $this->observation->refresh();
        $this->assertEquals('retrying', $this->observation->status);
        $this->assertDatabaseCount('facts', 0);
    }

    public function test_overloaded_provider_surfaces_as_ai_retrying_in_onboarding_status(): void
    {
        $this->fake->queueException(
            new AiProviderOverloadedException('Anthropic API is overloaded')
        );

        try {
            (new ProcessObservation($this->observation))->handle(
                $this->app->make(AnalystRegistry::class),
                $this->app->make(FactService::class),
                $this->app->make(KnowledgeService::class),
            );
        } catch (AiProviderOverloadedException) {
            // expected
        }

        $response = $this->actingAs($this->makeOwner())->getJson('/api/onboarding/status');

        // Freshly marked 'retrying' (< 30 s ago) — reported as waiting, no
        // re-dispatch yet, and not confused with failed or stalled states.
        $response->assertOk()
            ->assertJson([
                'crawl_succeeded' => true,
                'ai_retrying' => true,
                'ai_failed' => false,
                'pipeline_stalled' => false,
                'fact_count' => 0,
            ]);
    }

    public function test_status_endpoint_redispatches_stale_retrying_observation(): void
    {
        // Observation parked in 'retrying' 2 minutes ago — past the 30 s
        // re-dispatch throttle. The provider has recovered (valid fixture).
        Observation::withoutGlobalScopes()
            ->whereKey($this->observation->id)
            ->update(['status' => 'retrying', 'updated_at' => now()->subMinutes(2)]);

        $this->fake->queueFixture('website-facts');

        $response = $this->actingAs($this->makeOwner())->getJson('/api/onboarding/status');

        // The sync-queue re-dispatch ran inline: facts extracted, observation
        // processed, and this same poll already reflects the recovery.
        $response->assertOk()
            ->assertJson([
                'ai_retrying' => false,
                'ai_failed' => false,
                'fact_count' => 4,
            ]);

        $this->observation->refresh();
        $this->assertEquals('processed', $this->observation->status);
    }

    private function makeOwner(): User
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner@cbbauctions.com',
            'password' => 'secret-password',
        ]);

        CompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        return $user;
    }

    public function test_marks_failed_when_ai_provider_throws(): void
    {
        // Queue no responses — FakeAiProvider will throw on empty queue

        $this->expectException(\RuntimeException::class);

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(AnalystRegistry::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->observation->refresh();
        $this->assertEquals('failed', $this->observation->status);
    }
}
