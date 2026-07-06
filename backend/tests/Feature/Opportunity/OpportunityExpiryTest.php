<?php

namespace Tests\Feature\Opportunity;

use App\Jobs\ExpireOpportunities;
use App\Models\Company;
use App\Models\Opportunity;
use App\Services\Opportunity\OpportunityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_open_opportunities_past_expiry_date(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'featured_item',
            'title' => 'Past expiry',
            'description' => 'Should be expired',
            'relevance_score' => 70,
            'timing_score' => 70,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => 65,
            'status' => 'open',
            'expires_at' => now()->subHours(2),
            'detected_at' => now()->subDay(),
        ]);

        (new ExpireOpportunities())->handle();

        $this->assertDatabaseHas('opportunities', [
            'company_id' => $company->id,
            'status' => 'expired',
        ]);
    }

    public function test_leaves_future_opportunities_untouched(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'urgency',
            'title' => 'Future expiry',
            'description' => 'Should stay open',
            'relevance_score' => 85,
            'timing_score' => 95,
            'confidence_score' => 80,
            'urgency_score' => 98,
            'composite_score' => 89,
            'status' => 'open',
            'expires_at' => now()->addHours(12),
            'detected_at' => now()->subHour(),
        ]);

        (new ExpireOpportunities())->handle();

        $this->assertDatabaseHas('opportunities', [
            'company_id' => $company->id,
            'status' => 'open',
        ]);
    }

    public function test_expired_opportunity_no_longer_suppresses_fresh_detection(): void
    {
        // The engine's dedupe (hasDuplicate) must only consider open/selected
        // rows. If an expired opportunity still counted, the same opportunity
        // type could never be re-detected after it lapses — the loop would
        // silently stop producing recommendations of that type.
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 're_engagement',
            'subject_type' => null,
            'subject_id' => null,
            'title' => 'Old push',
            'description' => 'Expired re-engagement opportunity',
            'relevance_score' => 70,
            'timing_score' => 70,
            'confidence_score' => 60,
            'urgency_score' => 48,
            'composite_score' => 64,
            'status' => 'open',
            'expires_at' => now()->subHour(),
            'detected_at' => now()->subDays(8),
        ]);

        $repository = new OpportunityRepository();

        // Still open → suppresses.
        $this->assertTrue($repository->hasDuplicate($company->id, 're_engagement', null, null));

        (new ExpireOpportunities())->handle();

        // Expired → no longer suppresses re-detection.
        $this->assertFalse($repository->hasDuplicate($company->id, 're_engagement', null, null));
    }

    public function test_ignores_opportunities_without_expiry(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 're_engagement',
            'title' => 'No expiry',
            'description' => 'Open indefinitely',
            'relevance_score' => 70,
            'timing_score' => 70,
            'confidence_score' => 60,
            'urgency_score' => 48,
            'composite_score' => 64,
            'status' => 'open',
            'expires_at' => null,
            'detected_at' => now()->subDays(2),
        ]);

        (new ExpireOpportunities())->handle();

        $this->assertDatabaseHas('opportunities', [
            'company_id' => $company->id,
            'status' => 'open',
        ]);
    }
}
