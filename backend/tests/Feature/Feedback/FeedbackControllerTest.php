<?php

namespace Tests\Feature\Feedback;

use App\Events\FeedbackSubmitted;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FeedbackControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_requires_auth(): void
    {
        $this->post('/app/feedback', ['score' => 8])->assertRedirect('/login');
    }

    public function test_store_creates_feedback_and_fires_event(): void
    {
        Event::fake([FeedbackSubmitted::class]);
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/feedback', ['score' => 9, 'comment' => 'Loving the recommendations.'])
            ->assertRedirect();

        $this->assertDatabaseHas('feedback', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => 9,
            'comment' => 'Loving the recommendations.',
        ]);

        Event::assertDispatched(FeedbackSubmitted::class, fn (FeedbackSubmitted $event): bool => $event->feedback->score === 9);
    }

    public function test_store_allows_no_comment(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/feedback', ['score' => 5]);

        $this->assertDatabaseHas('feedback', ['company_id' => $company->id, 'score' => 5, 'comment' => null]);
    }

    public function test_store_rejects_score_out_of_range(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/feedback', ['score' => 11])->assertSessionHasErrors('score');
        $this->actingAs($user)->post('/app/feedback', ['score' => 0])->assertSessionHasErrors('score');

        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_store_rejects_a_comment_over_500_characters(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/feedback', ['score' => 7, 'comment' => str_repeat('a', 501)])
            ->assertSessionHasErrors('comment');
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
