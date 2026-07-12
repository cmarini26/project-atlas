<?php

namespace Tests\Feature\Feedback;

use App\Models\Approval;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Feedback;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Feedback\FeedbackPromptEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackPromptEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private FeedbackPromptEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eligibility = $this->app->make(FeedbackPromptEligibility::class);
    }

    private function approvedRecommendation(Company $company, \DateTimeInterface $actedAt): void
    {
        $recommendation = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'approved',
        ]);

        Approval::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'approvable_type' => Recommendation::class,
            'approvable_id' => $recommendation->id,
            'user_id' => User::factory()->create()->id,
            'action' => 'approved',
            'acted_at' => $actedAt,
        ]);
    }

    public function test_shows_for_an_owner_with_an_approval_over_24h_old(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->approvedRecommendation($company, now()->subDays(2));

        $this->assertTrue($this->eligibility->shouldShow($user, $membership));
    }

    public function test_does_not_show_for_a_member_role(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member']);
        $this->approvedRecommendation($company, now()->subDays(2));

        $this->assertFalse($this->eligibility->shouldShow($user, $membership));
    }

    public function test_does_not_show_when_the_approval_is_less_than_24h_old(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->approvedRecommendation($company, now()->subHours(2));

        $this->assertFalse($this->eligibility->shouldShow($user, $membership));
    }

    public function test_does_not_show_when_there_is_no_approval_at_all(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->assertFalse($this->eligibility->shouldShow($user, $membership));
    }

    public function test_does_not_show_when_the_user_submitted_feedback_within_90_days(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->approvedRecommendation($company, now()->subDays(2));

        Feedback::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => 9,
        ]);

        $this->assertFalse($this->eligibility->shouldShow($user, $membership));
    }

    public function test_shows_again_once_90_days_have_passed_since_the_last_submission(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $membership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->approvedRecommendation($company, now()->subDays(2));

        $feedback = Feedback::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => 9,
        ]);
        $feedback->forceFill(['created_at' => now()->subDays(91)])->save();

        $this->assertTrue($this->eligibility->shouldShow($user, $membership));
    }

    public function test_shared_prop_reflects_eligibility_on_a_real_page_load(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('show_feedback_prompt', false));

        $this->approvedRecommendation($company, now()->subDays(2));

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertInertia(fn ($page) => $page->where('show_feedback_prompt', true));
    }
}
