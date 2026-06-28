<?php

namespace Tests\Feature\Brain;

use App\Events\FactExtracted;
use App\Events\KnowledgeSynthesized;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BusinessBrainCacheTest extends TestCase
{
    use RefreshDatabase;

    private BusinessBrainService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(BusinessBrainService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Cache Test Co',
            'slug' => 'cache-test-co',
        ]);

        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'mixed',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
        ]);
    }

    // --- Cache key format ---

    public function test_cache_key_format(): void
    {
        $key = BusinessBrainService::cacheKey('01HZ123');

        $this->assertSame('brain:01HZ123', $key);
    }

    // --- Cache population ---

    public function test_result_is_stored_in_cache_after_first_call(): void
    {
        $this->assertFalse(Cache::has(BusinessBrainService::cacheKey($this->company->id)));

        $this->service->for($this->company);

        $this->assertTrue(Cache::has(BusinessBrainService::cacheKey($this->company->id)));
    }

    public function test_second_call_returns_cached_result(): void
    {
        // First call — assembles and caches
        $brain1 = $this->service->for($this->company);
        $factCount = $brain1->activeFacts->count();

        // Add a new fact AFTER the cache is populated
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'value' => json_encode('New Value'),
            'data_type' => 'string',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        // Second call — should still return the cached (stale) result
        $brain2 = $this->service->for($this->company);

        $this->assertSame($factCount, $brain2->activeFacts->count());
    }

    // --- Cache invalidation via static method ---

    public function test_invalidate_clears_cache_key(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(Cache::has(BusinessBrainService::cacheKey($this->company->id)));

        BusinessBrainService::invalidate($this->company->id);

        $this->assertFalse(Cache::has(BusinessBrainService::cacheKey($this->company->id)));
    }

    public function test_after_invalidation_fresh_data_is_assembled(): void
    {
        // Populate cache
        $brain1 = $this->service->for($this->company);
        $this->assertCount(0, $brain1->activeFacts);

        // Add fact
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'value' => json_encode('Updated'),
            'data_type' => 'string',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        // Invalidate
        BusinessBrainService::invalidate($this->company->id);

        // Next call assembles fresh data
        $brain2 = $this->service->for($this->company);
        $this->assertCount(1, $brain2->activeFacts);
    }

    // --- Cache invalidation via FactExtracted event ---

    public function test_fact_extracted_event_invalidates_cache(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(Cache::has(BusinessBrainService::cacheKey($this->company->id)));

        $fact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.description',
            'value' => json_encode('A test business'),
            'data_type' => 'string',
            'confidence' => 80,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        FactExtracted::dispatch($fact);

        $this->assertFalse(Cache::has(BusinessBrainService::cacheKey($this->company->id)));
    }

    public function test_knowledge_synthesized_event_invalidates_cache(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(Cache::has(BusinessBrainService::cacheKey($this->company->id)));

        $knowledge = Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'context',
            'subject' => 'business',
            'body' => 'Some knowledge',
            'confidence' => 85,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        KnowledgeSynthesized::dispatch($knowledge);

        $this->assertFalse(Cache::has(BusinessBrainService::cacheKey($this->company->id)));
    }

    // --- Event from a different company does not invalidate this company's cache ---

    public function test_fact_extracted_from_other_company_does_not_invalidate_cache(): void
    {
        $this->service->for($this->company);

        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'status' => 'active',
        ]);

        $fact = Fact::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'key' => 'business.name',
            'value' => json_encode('Other'),
            'data_type' => 'string',
            'confidence' => 80,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        FactExtracted::dispatch($fact);

        // This company's cache must still be populated
        $this->assertTrue(Cache::has(BusinessBrainService::cacheKey($this->company->id)));
    }
}
