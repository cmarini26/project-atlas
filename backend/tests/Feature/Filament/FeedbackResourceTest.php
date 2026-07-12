<?php

namespace Tests\Feature\Filament;

use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFeedback(): Feedback
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $user = User::factory()->create(['name' => 'Jamie Customer']);

        return Feedback::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => 9,
            'comment' => 'Really useful recommendations so far.',
        ]);
    }

    public function test_superadmin_can_view_the_feedback_list(): void
    {
        $admin = User::factory()->superadmin()->create();
        $this->makeFeedback();

        $response = $this->actingAs($admin)->get('/admin/feedback');

        $response->assertSuccessful();
        $response->assertSee('CBB Auctions');
        $response->assertSee('Jamie Customer');
    }

    public function test_superadmin_can_view_a_feedback_detail_page(): void
    {
        $admin = User::factory()->superadmin()->create();
        $feedback = $this->makeFeedback();

        $response = $this->actingAs($admin)->get("/admin/feedback/{$feedback->id}");

        $response->assertSuccessful();
        $response->assertSee('Really useful recommendations so far.');
    }

    public function test_regular_user_cannot_view_the_feedback_list(): void
    {
        $user = User::factory()->create();
        $this->makeFeedback();

        $this->actingAs($user)->get('/admin/feedback')->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->makeFeedback();

        $this->get('/admin/feedback')->assertRedirect('/admin/login');
    }

    public function test_the_feedback_resource_has_no_create_page(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->get('/admin/feedback/create')->assertNotFound();
    }
}
