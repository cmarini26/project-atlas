<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationCreated;
use App\Services\Team\TeamInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        [$company, $actor] = $this->managerContext($request);

        $members = CompanyMembership::withoutGlobalScopes()
            ->with('user:id,name,email')
            ->where('company_id', $company->id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 WHEN 'member' THEN 3 ELSE 4 END")
            ->get()
            ->map(function (CompanyMembership $membership) use ($actor): array {
                $user = $membership->user;
                abort_unless($user instanceof User, 500);

                return [
                    'id' => $membership->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $membership->role,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                    'can_manage' => $this->canManageRole($actor->role, $membership->role),
                ];
            });

        $pending = TeamInvitation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'can_manage' => $this->canManageRole($actor->role, $invitation->role),
            ]);

        return Inertia::render('App/Settings/Team/Index', [
            'members' => $members,
            'pending_invitations' => $pending,
            'actor_role' => $actor->role,
            'allowed_invitation_roles' => $actor->role === 'owner' ? ['admin', 'member', 'viewer'] : ['member', 'viewer'],
        ]);
    }

    public function invite(Request $request, TeamInvitationService $invitations): RedirectResponse
    {
        [$company, $actor] = $this->managerContext($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $allowedRoles = $actor->role === 'owner' ? ['admin', 'member', 'viewer'] : ['member', 'viewer'];
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in($allowedRoles)],
        ]);

        $result = $invitations->create($company, $user, $validated['email'], $validated['role']);
        $result['invitation']->load('company');

        Notification::route('mail', $result['invitation']->email)
            ->notify(new TeamInvitationCreated($result['invitation'], $result['token']));

        return back()->with('success', 'Invitation sent.');
    }

    public function update(Request $request, string $membership): RedirectResponse
    {
        [$company, $actor] = $this->managerContext($request);
        $target = CompanyMembership::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->findOrFail($membership);

        $this->ensureCanManage($actor, $target->role);
        $allowedRoles = $actor->role === 'owner' ? ['admin', 'member', 'viewer'] : ['member', 'viewer'];
        $validated = $request->validate(['role' => ['required', Rule::in($allowedRoles)]]);
        $target->update(['role' => $validated['role']]);

        return back()->with('success', 'Team member role updated.');
    }

    public function remove(Request $request, string $membership): RedirectResponse
    {
        [$company, $actor] = $this->managerContext($request);
        $target = CompanyMembership::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->findOrFail($membership);

        $this->ensureCanManage($actor, $target->role);
        $target->delete();

        return back()->with('success', 'Team member removed.');
    }

    public function revoke(Request $request, string $invitation): RedirectResponse
    {
        [$company, $actor] = $this->managerContext($request);
        $target = TeamInvitation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->findOrFail($invitation);

        $this->ensureCanManage($actor, $target->role);
        $target->update(['revoked_at' => now()]);

        return back()->with('success', 'Invitation revoked.');
    }

    /** @return array{Company, CompanyMembership} */
    private function managerContext(Request $request): array
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        /** @var CompanyMembership $membership */
        $membership = $request->attributes->get('membership');
        abort_unless(in_array($membership->role, ['owner', 'admin'], true), 403);

        return [$company, $membership];
    }

    private function ensureCanManage(CompanyMembership $actor, string $targetRole): void
    {
        if (! $this->canManageRole($actor->role, $targetRole)) {
            throw ValidationException::withMessages(['role' => 'You cannot manage this team member.']);
        }
    }

    private function canManageRole(string $actorRole, string $targetRole): bool
    {
        if ($targetRole === 'owner') {
            return false;
        }

        return $actorRole === 'owner' || ($actorRole === 'admin' && in_array($targetRole, ['member', 'viewer'], true));
    }
}
