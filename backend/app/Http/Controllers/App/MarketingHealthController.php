<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MarketingHealthScore;
use App\Services\MarketingHealth\MarketingHealthService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only Marketing Health dashboard — Milestone 13 Phase 1. Does not
 * trigger a recompute on page view; scores are computed by
 * MarketingHealthService::recompute(), driven by ProcessObservation.
 */
class MarketingHealthController extends Controller
{
    public function __construct(private readonly MarketingHealthService $marketingHealthService) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $scores = $this->marketingHealthService->currentFor($company);
        $composite = $this->marketingHealthService->compositeFor($company);

        return Inertia::render('App/MarketingHealth', [
            'composite' => $composite,
            'dimensions' => $scores
                ->map(fn (MarketingHealthScore $s) => [
                    'dimension' => $s->dimension,
                    'score' => $s->score,
                    'confidence' => $s->confidence,
                    'evidence' => $s->evidence,
                    'computed_at' => (string) $s->computed_at,
                ])
                ->values()
                ->all(),
        ]);
    }
}
