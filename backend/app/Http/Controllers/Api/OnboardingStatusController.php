<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $membership) {
            return response()->json([
                'twin_status' => null,
                'fact_count' => 0,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
            ]);
        }

        $companyId = $membership->company_id;

        $twin = DigitalTwin::where('company_id', $companyId)->first();

        $pendingRecommendation = Recommendation::where('company_id', $companyId)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        return response()->json([
            'twin_status' => $twin?->status,
            'fact_count' => Fact::where('company_id', $companyId)->where('is_current', true)->count(),
            'opportunity_count' => Opportunity::where('company_id', $companyId)->where('status', 'open')->count(),
            'recommendation_count' => Recommendation::where('company_id', $companyId)->where('status', 'pending')->count(),
            'first_recommendation_id' => $pendingRecommendation?->id,
        ]);
    }
}
