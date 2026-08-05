<?php

namespace Tests\Feature\Team;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login_and_login_returns_to_invitation(): void
    {
        [$token] = $this->invitation();

        $this->get("/team-invitations/{$token}")->assertRedirect('/login');

        $invitee = User::factory()->create(['email' => 'invitee@example.com', 'password' => 'password']);
        $this->post('/login', ['email' => $invitee->email, 'password' => 'password'])
            ->assertRedirect("/team-invitations/{$token}");
    }

    public function test_matching_user_can_review_and_accept_invitation(): void
    {
        [$token, $company] = $this->invitation();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $this->actingAs($invitee)->get("/team-invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TeamInvitations/Show')
                ->where('invitation.company_name', $company->name)
                ->where('invitation.role', 'member'));

        $this->actingAs($invitee)->post("/team-invitations/{$token}")
            ->assertRedirect('/app')
            ->assertSessionHas('selected_company_id', $company->id);

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $invitee->id,
            'role' => 'member',
        ]);
    }

    public function test_registration_honors_intended_invitation_url(): void
    {
        [$token] = $this->invitation();
        $this->get("/team-invitations/{$token}")->assertRedirect('/login');

        $this->post('/register', [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect("/team-invitations/{$token}");
    }

    /** @return array{string, Company} */
    private function invitation(): array
    {
        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Invitation Company',
            'slug' => 'invitation-company',
            'industry' => 'services',
        ]);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $result = $this->app->make(TeamInvitationService::class)
            ->create($company, $owner, 'invitee@example.com', 'member');

        return [$result['token'], $company];
    }
}
