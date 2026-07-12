<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTourControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_requires_auth(): void
    {
        $this->post('/app/tour/complete')->assertRedirect('/login');
    }

    public function test_complete_sets_product_tour_completed_at(): void
    {
        [$user] = $this->userWithCompany();

        $this->assertNull($user->product_tour_completed_at);

        $this->actingAs($user)->post('/app/tour/complete')->assertRedirect();

        $this->assertNotNull($user->fresh()->product_tour_completed_at);
    }

    public function test_shared_props_reflect_tour_state_before_and_after_completion(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('auth.user.has_completed_tour', false));

        $this->actingAs($user)->post('/app/tour/complete');

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('auth.user.has_completed_tour', true));
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
