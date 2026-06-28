<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Opportunity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpportunityController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $opportunities = Opportunity::where('company_id', $company->id)
            ->where('status', 'open')
            ->orderByDesc('composite_score')
            ->get()
            ->map(fn (Opportunity $o) => [
                'id' => $o->id,
                'type' => $o->type,
                'title' => $o->title ?? $o->type,
                'description' => $o->description ?? '',
                'composite_score' => $o->composite_score,
                'relevance_score' => $o->relevance_score,
                'timing_score' => $o->timing_score,
                'confidence_score' => $o->confidence_score,
                'urgency_score' => $o->urgency_score,
                'status' => $o->status,
                'detected_at' => $o->detected_at->toIso8601String(),
                'expires_at' => $o->expires_at?->toIso8601String(),
                'subject_type' => $o->subject_type,
                'subject_id' => $o->subject_id,
            ])
            ->values()
            ->all();

        return Inertia::render('App/Opportunities', [
            'opportunities' => $opportunities,
        ]);
    }
}
