<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnboardingChecklistController extends Controller
{
    public function dismiss(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->update(['checklist_dismissed_at' => now()]);

        return back();
    }

    public function dismissChannelSetup(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->update(['channel_setup_reminder_dismissed_at' => now()]);

        return back();
    }
}
