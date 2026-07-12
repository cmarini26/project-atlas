<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingChecklistControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dismiss_requires_auth(): void
    {
        $this->post('/app/checklist/dismiss')->assertRedirect('/login');
    }

    public function test_dismiss_sets_checklist_dismissed_at(): void
    {
        [$user] = $this->userWithCompany();

        $this->assertNull($user->checklist_dismissed_at);

        $this->actingAs($user)->post('/app/checklist/dismiss')->assertRedirect();

        $this->assertNotNull($user->fresh()->checklist_dismissed_at);
    }

    public function test_shared_props_reflect_dismissal_state(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('auth.user.has_dismissed_checklist', false));

        $this->actingAs($user)->post('/app/checklist/dismiss');

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('auth.user.has_dismissed_checklist', true));
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
