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

        BusinessBrainService::flush();

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

    // --- Memoization ---

    public function test_result_is_memoized_after_first_call(): void
    {
        $this->assertFalse(BusinessBrainService::isMemoized($this->company->id));

        $this->service->for($this->company);

        $this->assertTrue(BusinessBrainService::isMemoized($this->company->id));
    }

    public function test_second_call_returns_memoized_result(): void
    {
        // First call — assembles and memoizes
        $brain1 = $this->service->for($this->company);
        $factCount = $brain1->activeFacts->count();

        // Add a new fact AFTER the memo is populated
        Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'value' => json_encode('New Value'),
            'data_type' => 'string',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        // Second call — should still return the memoized (stale) result
        $brain2 = $this->service->for($this->company);

        $this->assertSame($factCount, $brain2->activeFacts->count());
        $this->assertSame($brain1, $brain2);
    }

    public function test_memo_expires_after_ttl(): void
    {
        $brain1 = $this->service->for($this->company);

        $this->travel(6)->minutes();

        $this->assertFalse(BusinessBrainService::isMemoized($this->company->id));

        $brain2 = $this->service->for($this->company);

        $this->assertNotSame($brain1, $brain2);
    }

    // --- Regression: the brain must never enter an external cache store ---

    public function test_brain_is_never_written_to_the_shared_cache_store(): void
    {
        // The brain is a graph of Eloquent models. Laravel's cache hardening
        // (`serializable_classes => false` in config/cache.php) refuses to
        // unserialize objects from external stores — a Redis-cached brain came
        // back as __PHP_Incomplete_Class and every consumer crashed with a
        // TypeError (P0: CommitDecision failed before any AI call).
        $this->service->for($this->company);

        $this->assertNull(Cache::get("brain:{$this->company->id}"));
    }

    // --- Invalidation via static method ---

    public function test_invalidate_clears_memo(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(BusinessBrainService::isMemoized($this->company->id));

        BusinessBrainService::invalidate($this->company->id);

        $this->assertFalse(BusinessBrainService::isMemoized($this->company->id));
    }

    public function test_after_invalidation_fresh_data_is_assembled(): void
    {
        // Populate memo
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

    // --- Invalidation via events ---

    public function test_fact_extracted_event_invalidates_memo(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(BusinessBrainService::isMemoized($this->company->id));

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

        $this->assertFalse(BusinessBrainService::isMemoized($this->company->id));
    }

    public function test_knowledge_synthesized_event_invalidates_memo(): void
    {
        $this->service->for($this->company);
        $this->assertTrue(BusinessBrainService::isMemoized($this->company->id));

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

        $this->assertFalse(BusinessBrainService::isMemoized($this->company->id));
    }

    // --- Event from a different company does not invalidate this company's memo ---

    public function test_fact_extracted_from_other_company_does_not_invalidate_memo(): void
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

        // This company's memo must still be populated
        $this->assertTrue(BusinessBrainService::isMemoized($this->company->id));
    }
}
