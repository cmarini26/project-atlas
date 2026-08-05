<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_members_and_pending_invitations(): void
    {
        [$owner, $company] = $this->userWithCompany('owner');
        $member = User::factory()->create();
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
        TeamInvitation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'email' => 'pending@example.com',
            'normalized_email' => 'pending@example.com',
            'role' => 'viewer',
            'invited_by' => $owner->id,
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner)->get('/app/settings/team')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('App/Settings/Team/Index')
                ->has('members', 2)
                ->has('pending_invitations', 1)
                ->where('actor_role', 'owner')
                ->where('allowed_invitation_roles', ['admin', 'member', 'viewer']));
    }

    public function test_owner_can_invite_admin_and_notification_is_sent_on_demand(): void
    {
        Notification::fake();
        [$owner] = $this->userWithCompany('owner');

        $this->actingAs($owner)->post('/app/settings/team/invitations', [
            'email' => 'admin@example.com',
            'role' => 'admin',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_invitations', [
            'normalized_email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        Notification::assertSentOnDemand(TeamInvitationCreated::class);
    }

    public function test_admin_can_invite_member_but_not_admin(): void
    {
        Notification::fake();
        [$admin] = $this->userWithCompany('admin');

        $this->actingAs($admin)->post('/app/settings/team/invitations', [
            'email' => 'member@example.com',
            'role' => 'member',
        ])->assertRedirect();

        $this->actingAs($admin)->post('/app/settings/team/invitations', [
            'email' => 'other-admin@example.com',
            'role' => 'admin',
        ])->assertSessionHasErrors('role');
    }

    public function test_member_and_viewer_cannot_access_team_settings(): void
    {
        foreach (['member', 'viewer'] as $role) {
            [$user] = $this->userWithCompany($role, $role);
            $this->actingAs($user)->get('/app/settings/team')->assertForbidden();
            $this->actingAs($user)->post('/app/settings/team/invitations', [
                'email' => "{$role}@example.com",
                'role' => 'viewer',
            ])->assertForbidden();
        }
    }

    public function test_owner_can_update_and_remove_non_owner_member(): void
    {
        [$owner, $company] = $this->userWithCompany('owner');
        $member = User::factory()->create();
        $membership = CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner)->patch("/app/settings/team/members/{$membership->id}", ['role' => 'viewer'])->assertRedirect();
        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'role' => 'viewer']);

        $this->actingAs($owner)->delete("/app/settings/team/members/{$membership->id}")->assertRedirect();
        $this->assertDatabaseMissing('company_memberships', ['id' => $membership->id]);
    }

    public function test_admin_cannot_manage_owner_or_admin(): void
    {
        [$admin, $company] = $this->userWithCompany('admin');
        $owner = User::factory()->create();
        $ownerMembership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $otherAdmin = User::factory()->create();
        $adminMembership = CompanyMembership::create(['company_id' => $company->id, 'user_id' => $otherAdmin->id, 'role' => 'admin']);

        $this->actingAs($admin)->delete("/app/settings/team/members/{$ownerMembership->id}")->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch("/app/settings/team/members/{$adminMembership->id}", ['role' => 'member'])->assertSessionHasErrors('role');
    }

    public function test_memberships_and_invitations_are_tenant_scoped(): void
    {
        [$owner] = $this->userWithCompany('owner');
        [, $otherCompany] = $this->userWithCompany('owner', 'other-owner');
        $otherMember = User::factory()->create();
        $otherMembership = CompanyMembership::create(['company_id' => $otherCompany->id, 'user_id' => $otherMember->id, 'role' => 'member']);
        $otherInvitation = TeamInvitation::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'email' => 'other@example.com',
            'normalized_email' => 'other@example.com',
            'role' => 'member',
            'invited_by' => CompanyMembership::withoutGlobalScopes()->where('company_id', $otherCompany->id)->value('user_id'),
            'token_hash' => hash('sha256', 'other-token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)->delete("/app/settings/team/members/{$otherMembership->id}")->assertNotFound();
        $this->actingAs($owner)->delete("/app/settings/team/invitations/{$otherInvitation->id}")->assertNotFound();
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role, string $suffix = 'primary'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create([
            'name' => "Team {$suffix}",
            'slug' => "team-{$suffix}-".str()->lower(str()->random(6)),
            'industry' => 'services',
        ]);
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return [$user, $company];
    }
}
