<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessBrainControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/app/brain')->assertRedirect('/login');
    }

    public function test_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/brain')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Brain'));
    }

    public function test_returns_null_twin_when_no_twin_exists(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/brain')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('twin', null));
    }

    public function test_returns_twin_data_when_active(): void
    {
        [$user, $company] = $this->userWithCompany();

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'active',
            'health_score' => 75,
        ]);

        Fact::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'key' => 'total_products',
            'value' => '42',
            'data_type' => 'integer',
            'is_current' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/brain')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('twin.status', 'active')
                ->where('twin.health_score', 75)
                ->has('facts', 1)
            );
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
