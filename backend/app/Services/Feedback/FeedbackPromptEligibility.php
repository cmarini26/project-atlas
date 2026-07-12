<?php

namespace App\Services\Feedback;

use App\Models\Approval;
use App\Models\CompanyMembership;
use App\Models\Feedback;
use App\Models\Recommendation;
use App\Models\User;

class FeedbackPromptEligibility
{
    /**
     * True when: the user's role at this company is owner/admin, at least
     * one Recommendation approval happened more than 24h ago (the roadmap's
     * trigger point), and this user hasn't submitted feedback in the last
     * 90 days (the roadmap's non-repeat window).
     */
    public function shouldShow(User $user, CompanyMembership $membership): bool
    {
        if (! in_array($membership->role, ['owner', 'admin'], true)) {
            return false;
        }

        $hasEligibleApproval = Approval::withoutGlobalScopes()
            ->where('company_id', $membership->company_id)
            ->where('approvable_type', Recommendation::class)
            ->whereIn('action', ['approved', 'edited_and_approved'])
            ->where('acted_at', '<=', now()->subDay())
            ->exists();

        if (! $hasEligibleApproval) {
            return false;
        }

        $recentlySubmitted = Feedback::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->exists();

        return ! $recentlySubmitted;
    }
}
