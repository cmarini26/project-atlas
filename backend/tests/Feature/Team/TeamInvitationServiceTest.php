<?php

namespace Tests\Feature\Team;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeamInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_only_a_hash_of_the_single_use_token(): void
    {
        [$company, $owner] = $this->companyWithOwner();

        $result = $this->service()->create($company, $owner, ' Teammate@Example.com ', 'member');

        $this->assertNotSame($result['token'], $result['invitation']->token_hash);
        $this->assertSame(hash('sha256', $result['token']), $result['invitation']->token_hash);
        $this->assertSame('teammate@example.com', $result['invitation']->normalized_email);
        $this->assertTrue($result['invitation']->expires_at->isSameDay(now()->addDays(7)));
    }

    public function test_matching_user_can_accept_invitation_once(): void
    {
        [$company, $owner] = $this->companyWithOwner();
        $invitee = User::factory()->create(['email' => 'teammate@example.com']);
        $result = $this->service()->create($company, $owner, 'teammate@example.com', 'viewer');

        $membership = $this->service()->accept($invitee, $result['token']);

        $this->assertSame('viewer', $membership->role);
        $this->assertSame($owner->id, $membership->invited_by);
        $this->assertNotNull($membership->joined_at);
        $this->assertNotNull($result['invitation']->fresh()->accepted_at);

        $this->expectException(ValidationException::class);
        $this->service()->accept($invitee, $result['token']);
    }

    public function test_wrong_email_cannot_accept_invitation(): void
    {
        [$company, $owner] = $this->companyWithOwner();
        $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
        $result = $this->service()->create($company, $owner, 'teammate@example.com', 'member');

        try {
            $this->service()->accept($wrongUser, $result['token']);
            $this->fail('Expected invitation acceptance to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Sign in with the email address that received this invitation.',
                $exception->errors()['invitation'][0],
            );
        }

        $this->assertDatabaseMissing('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $wrongUser->id,
        ]);
    }

    public function test_expired_or_revoked_invitation_cannot_be_accepted(): void
    {
        [$company, $owner] = $this->companyWithOwner();
        $invitee = User::factory()->create(['email' => 'teammate@example.com']);
        $expired = $this->service()->create($company, $owner, $invitee->email, 'member');
        $expired['invitation']->update(['expires_at' => now()->subMinute()]);

        try {
            $this->service()->accept($invitee, $expired['token']);
            $this->fail('Expected expired invitation acceptance to fail.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('company_memberships', ['company_id' => $company->id, 'user_id' => $invitee->id]);
        }

        $revoked = $this->service()->create($company, $owner, $invitee->email, 'member');
        $revoked['invitation']->update(['revoked_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->service()->accept($invitee, $revoked['token']);
    }

    public function test_duplicate_member_or_pending_invitation_is_rejected(): void
    {
        [$company, $owner] = $this->companyWithOwner();
        $member = User::factory()->create(['email' => 'member@example.com']);
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        try {
            $this->service()->create($company, $owner, $member->email, 'viewer');
            $this->fail('Expected existing membership to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('This person is already a member of the workspace.', $exception->errors()['email'][0]);
        }

        $this->service()->create($company, $owner, 'pending@example.com', 'member');

        $this->expectException(ValidationException::class);
        $this->service()->create($company, $owner, 'PENDING@example.com', 'viewer');
    }

    /** @return array{Company, User} */
    private function companyWithOwner(): array
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Atlas Team Test',
            'slug' => 'atlas-team-test',
            'industry' => 'services',
        ]);
        $owner = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('password'),
        ]);
        CompanyMembership::create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$company, $owner];
    }

    private function service(): TeamInvitationService
    {
        return $this->app->make(TeamInvitationService::class);
    }
}
