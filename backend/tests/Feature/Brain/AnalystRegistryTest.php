<?php

namespace Tests\Feature\Brain;

use App\Models\Company;
use App\Models\Observation;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\Exceptions\UnsupportedObservationException;
use App\Services\Analyst\InstagramAnalyst;
use App\Services\Analyst\WebsiteAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalystRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);
    }

    private function makeObservation(string $sourceType): Observation
    {
        return Observation::withoutGlobalScopes()->make([
            'company_id' => $this->company->id,
            'source_type' => $sourceType,
            'source_identifier' => 'anything',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_resolves_website_analyst_for_crawl_observations(): void
    {
        $registry = $this->app->make(AnalystRegistry::class);

        $this->assertInstanceOf(WebsiteAnalyst::class, $registry->resolve($this->makeObservation('crawl')));
    }

    public function test_resolves_instagram_analyst_for_social_observations(): void
    {
        $registry = $this->app->make(AnalystRegistry::class);

        $this->assertInstanceOf(InstagramAnalyst::class, $registry->resolve($this->makeObservation('social')));
    }

    public function test_throws_for_unsupported_source_type(): void
    {
        $registry = $this->app->make(AnalystRegistry::class);

        $this->expectException(UnsupportedObservationException::class);
        $registry->resolve($this->makeObservation('feed'));
    }

    public function test_registry_contains_all_registered_analysts(): void
    {
        $registry = $this->app->make(AnalystRegistry::class);

        $this->assertCount(2, $registry->all());
    }
}
