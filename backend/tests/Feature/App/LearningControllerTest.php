<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Learning;
use App\Models\LearningApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/app/learning')->assertRedirect('/login');
    }

    public function test_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/learning')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Learning'));
    }

    public function test_returns_learnings_for_company(): void
    {
        [$user, $company] = $this->userWithCompany();

        Learning::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'source_type' => 'approval',
            'source_id' => '01xxxxxxxxxxxxxxxxxxxxxxxx',
            'subject_type' => 'campaign',
            'signal' => 'high_engagement',
            'value' => ['metric' => 'clicks', 'value' => 120],
        ]);

        $this->actingAs($user)
            ->get('/app/learning')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('learnings.data', 1)
                ->where('learnings.total', 1)
            );
    }

    public function test_does_not_return_other_company_learnings(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        Learning::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'source_type' => 'approval',
            'source_id' => '01xxxxxxxxxxxxxxxxxxxxxxxx',
            'subject_type' => 'campaign',
            'signal' => 'high_engagement',
            'value' => [],
        ]);

        $this->actingAs($user)
            ->get('/app/learning')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('learnings.data', 0));
    }

    public function test_returns_applied_effects(): void
    {
        [$user, $company] = $this->userWithCompany();

        $learning = Learning::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'source_type' => 'approval',
            'source_id' => '01xxxxxxxxxxxxxxxxxxxxxxxx',
            'subject_type' => 'campaign',
            'signal' => 'high_engagement',
            'value' => [],
        ]);

        LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'learning_id' => $learning->id,
            'effects' => ['boosted_score' => true],
        ]);

        $this->actingAs($user)
            ->get('/app/learning')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('applied_effects', 1));
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
