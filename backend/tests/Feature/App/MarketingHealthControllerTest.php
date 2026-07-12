<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MarketingHealthScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/marketing-health')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/marketing-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/MarketingHealth'));
    }

    public function test_index_reports_null_composite_without_any_scores(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/marketing-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('composite', null)
                ->where('dimensions', [])
            );
    }

    public function test_index_includes_current_dimension_scores(): void
    {
        [$user, $company] = $this->userWithCompany();

        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'dimension' => 'website', 'score' => 82, 'confidence' => 90,
            'evidence' => [['label' => 'Last crawled 2 day(s) ago', 'source_type' => 'observation', 'source_id' => null, 'value' => null]],
            'computed_at' => now(), 'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/marketing-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('composite.score', 82)
                ->has('dimensions', 1)
                ->where('dimensions.0.dimension', 'website')
                ->where('dimensions.0.score', 82)
                ->has('dimensions.0.evidence', 1)
            );
    }

    public function test_index_omits_superseded_scores(): void
    {
        [$user, $company] = $this->userWithCompany();

        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'dimension' => 'website', 'score' => 40, 'confidence' => 90,
            'evidence' => [], 'computed_at' => now()->subDay(), 'is_current' => false,
        ]);
        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'dimension' => 'website', 'score' => 90, 'confidence' => 90,
            'evidence' => [], 'computed_at' => now(), 'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/marketing-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('dimensions', 1)
                ->where('dimensions.0.score', 90)
            );
    }

    public function test_index_does_not_leak_scores_across_companies(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        MarketingHealthScore::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'dimension' => 'website', 'score' => 90, 'confidence' => 90,
            'evidence' => [], 'computed_at' => now(), 'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/marketing-health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('dimensions', []));
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
