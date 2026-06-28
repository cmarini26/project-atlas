<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/app/opportunities')->assertRedirect('/login');
    }

    public function test_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/opportunities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Opportunities'));
    }

    public function test_returns_open_opportunities_for_company(): void
    {
        [$user, $company] = $this->userWithCompany();

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'featured_item',
            'title' => 'Test Opportunity',
            'description' => 'A test opportunity.',
            'status' => 'open',
            'composite_score' => 80,
            'relevance_score' => 80,
            'timing_score' => 80,
            'confidence_score' => 80,
            'urgency_score' => 80,
            'detected_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/opportunities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('opportunities', 1));
    }

    public function test_does_not_show_dismissed_opportunities(): void
    {
        [$user, $company] = $this->userWithCompany();

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'featured_item',
            'title' => 'Dismissed Opportunity',
            'description' => 'A dismissed opportunity.',
            'status' => 'dismissed',
            'composite_score' => 80,
            'relevance_score' => 80,
            'timing_score' => 80,
            'confidence_score' => 80,
            'urgency_score' => 80,
            'detected_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/opportunities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('opportunities', 0));
    }

    public function test_does_not_show_other_company_opportunities(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other Co', 'slug' => 'other-co']);

        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'featured_item',
            'title' => 'Test Opportunity',
            'description' => 'A test opportunity.',
            'status' => 'open',
            'composite_score' => 80,
            'relevance_score' => 80,
            'timing_score' => 80,
            'confidence_score' => 80,
            'urgency_score' => 80,
            'detected_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/opportunities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('opportunities', 0));
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }
}
