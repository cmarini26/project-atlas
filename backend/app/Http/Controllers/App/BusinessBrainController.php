<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\Observation;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessBrainController extends Controller
{
    public function __construct(private readonly BusinessBrainService $brainService) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $twin = DigitalTwin::where('company_id', $company->id)->first();

        if (! $twin || $twin->status === 'initializing') {
            return Inertia::render('App/Brain', [
                'twin' => $twin ? [
                    'status' => $twin->status,
                    'health_score' => $twin->health_score ?? 0,
                    'last_enriched_at' => $twin->last_enriched_at !== null ? (string) $twin->last_enriched_at : null,
                ] : null,
                'facts' => [],
                'knowledge' => [],
                'recent_observations' => [],
                'catalog' => null,
            ]);
        }

        $brain = $this->brainService->for($company);

        $catalog = $company->catalog()->first();

        $observations = Observation::where('company_id', $company->id)
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('App/Brain', [
            'twin' => [
                'status' => $twin->status,
                'health_score' => $twin->health_score ?? 0,
                'last_enriched_at' => $twin->last_enriched_at !== null ? (string) $twin->last_enriched_at : null,
            ],
            'facts' => collect($brain->activeFacts)
                ->map(fn (Fact $f) => [
                    'id' => $f->id,
                    'key' => $f->key,
                    'value' => $f->value,
                    'data_type' => $f->data_type ?? 'string',
                    'confidence' => $f->confidence,
                    'created_at' => $f->created_at?->toIso8601String() ?? '',
                ])
                ->values()
                ->all(),
            'knowledge' => collect($brain->activeKnowledge)
                ->map(fn (Knowledge $k) => [
                    'id' => $k->id,
                    'subject' => $k->subject,
                    'body' => $k->body,
                    'confidence' => $k->confidence,
                    'type' => $k->type,
                    'expires_at' => $k->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'recent_observations' => $observations->map(fn (Observation $o) => [
                'id' => $o->id,
                'status' => $o->status,
                'created_at' => $o->created_at?->toIso8601String() ?? '',
            ])->values()->all(),
            'catalog' => $catalog ? [
                'name' => $catalog->name,
                'type' => $catalog->type,
                'item_count' => CatalogItem::where('company_id', $company->id)->count(),
            ] : null,
        ]);
    }
}
