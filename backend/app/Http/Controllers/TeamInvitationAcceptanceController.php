<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Team\TeamInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token, TeamInvitationService $invitations): Response
    {
        $invitation = $invitations->findValid($token);

        return Inertia::render('TeamInvitations/Show', [
            'invitation' => [
                'company_name' => $invitation->company->name,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'token' => $token,
            'signed_in_email' => $request->user()?->email,
        ]);
    }

    public function accept(Request $request, string $token, TeamInvitationService $invitations): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = $invitations->accept($user, $token);
        $request->session()->put('selected_company_id', $membership->company_id);

        return redirect()->route('app.dashboard')->with('success', 'Invitation accepted. Welcome to the team.');
    }
}
