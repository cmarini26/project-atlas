<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Learning;
use App\Models\LearningApplication;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearningController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $learnings = Learning::where('company_id', $company->id)
            ->latest()
            ->paginate(20);

        $appliedEffects = LearningApplication::whereHas(
            'learning',
            fn ($q) => $q->where('company_id', $company->id)
        )
            ->with('learning')
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('App/Learning', [
            'learnings' => [
                'data' => collect($learnings->items())->map(fn (Learning $l) => [
                    'id' => $l->id,
                    'signal' => $l->signal,
                    'value' => $l->value ?? [],
                    'applied_at' => $l->applied_at?->toIso8601String(),
                    'source_type' => $l->source_type,
                    'created_at' => $l->created_at?->toIso8601String() ?? '',
                ])->values()->all(),
                'current_page' => $learnings->currentPage(),
                'last_page' => $learnings->lastPage(),
                'total' => $learnings->total(),
            ],
            'applied_effects' => $appliedEffects->map(fn (LearningApplication $a) => [
                'id' => $a->id,
                'effects' => $a->effects ?? [],
                'rolled_back_at' => $a->rolled_back_at?->toIso8601String(),
                'created_at' => $a->created_at->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
