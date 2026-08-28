<?php

namespace Tests\Feature\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Providers\OllamaAiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\CampaignPreparationAnalyst;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Brain\FactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

class FactExtractionProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $default;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->default);
    }

    private function crawlObservation(): Observation
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        DigitalTwin::withoutGlobalScopes()->create(['company_id' => $company->id, 'status' => 'initializing']);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://cbbauctions.com'],
            'status' => 'active',
        ]);

        return Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => json_encode([
                'url' => 'https://cbbauctions.com',
                'title' => 'CBB Auctions',
                'body_text' => 'We are a comic book auction house based in Ohio.',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_website_fact_extraction_uses_the_default_provider_when_no_override(): void
    {
        config()->set('ai.task_providers.fact_extraction', null);
        $this->default->queueFixture('website-facts');

        $this->app->make(WebsiteAnalyst::class)->analyze($this->crawlObservation());

        $this->default->assertPromptSent(FactExtractionPrompt::class);
    }

    public function test_override_routes_website_fact_extraction_to_the_named_provider(): void
    {
        config()->set('ai.task_providers.fact_extraction', 'ollama');

        $routed = new FakeAiProvider();
        $routed->queueFixture('website-facts');
        $this->app->instance(OllamaAiProvider::class, $routed);

        $this->app->make(WebsiteAnalyst::class)->analyze($this->crawlObservation());

        $routed->assertPromptSent(FactExtractionPrompt::class);
        $this->default->assertNothingSent();
    }

    public function test_override_does_not_affect_other_ai_tasks(): void
    {
        config()->set('ai.task_providers.fact_extraction', 'ollama');
        $this->app->instance(OllamaAiProvider::class, new FakeAiProvider());

        $prop = new ReflectionProperty(CampaignPreparationAnalyst::class, 'ai');
        $resolved = $prop->getValue($this->app->make(CampaignPreparationAnalyst::class));

        $this->assertSame($this->default, $resolved, 'Non-fact-extraction tasks must keep the default provider.');
        $this->assertSame($this->default, $this->app->make(AiProvider::class));
    }

    public function test_routed_fact_extraction_still_stores_facts_with_observation_provenance(): void
    {
        config()->set('ai.task_providers.fact_extraction', 'ollama');

        $routed = new FakeAiProvider();
        $routed->queueFixture('website-facts');
        $this->app->instance(OllamaAiProvider::class, $routed);

        $observation = $this->crawlObservation();
        $facts = $this->app->make(WebsiteAnalyst::class)->analyze($observation);
        $this->app->make(FactService::class)->storeExtracted($observation, $facts);

        $this->assertDatabaseCount('facts', 4);
        $this->assertDatabaseHas('facts', [
            'company_id' => $observation->company_id,
            'observation_id' => $observation->id,
            'key' => 'business.name',
            'is_current' => true,
        ]);
    }
}
