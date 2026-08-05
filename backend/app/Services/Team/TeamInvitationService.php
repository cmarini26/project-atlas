<?php

namespace App\Services\Team;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamInvitationService
{
    /** @return array{invitation: TeamInvitation, token: string} */
    public function create(Company $company, User $inviter, string $email, string $role): array
    {
        $normalizedEmail = Str::lower(trim($email));

        $existingUserId = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->value('id');

        if ($existingUserId !== null && CompanyMembership::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('user_id', $existingUserId)
            ->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a member of the workspace.']);
        }

        if (TeamInvitation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('normalized_email', $normalizedEmail)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists()) {
            throw ValidationException::withMessages(['email' => 'A pending invitation already exists for this email address.']);
        }

        $token = Str::random(64);
        $invitation = TeamInvitation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'email' => trim($email),
            'normalized_email' => $normalizedEmail,
            'role' => $role,
            'invited_by' => $inviter->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function findValid(string $token): TeamInvitation
    {
        $invitation = TeamInvitation::withoutGlobalScopes()
            ->with('company')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is invalid or has expired.']);
        }

        return $invitation;
    }

    public function accept(User $user, string $token): CompanyMembership
    {
        return DB::transaction(function () use ($user, $token): CompanyMembership {
            $invitation = TeamInvitation::withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is invalid or has expired.']);
            }

            if (Str::lower(trim($user->email)) !== $invitation->normalized_email) {
                throw ValidationException::withMessages(['invitation' => 'Sign in with the email address that received this invitation.']);
            }

            $membership = CompanyMembership::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $invitation->company_id, 'user_id' => $user->id],
                [
                    'role' => $invitation->role,
                    'invited_by' => $invitation->invited_by,
                    'joined_at' => now(),
                ],
            );

            $invitation->update(['accepted_at' => now()]);

            return $membership;
        });
    }
}
