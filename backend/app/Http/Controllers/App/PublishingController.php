<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Execution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublishingController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $executions = Execution::with(['channel', 'contentAsset'])
            ->where('company_id', $company->id)
            ->latest()
            ->paginate(20);

        return Inertia::render('App/Publishing', [
            'executions' => [
                'data' => collect($executions->items())->map(fn ($e) => [
                    'id' => $e->id,
                    'status' => $e->status,
                    'scheduled_at' => $e->scheduled_at?->toIso8601String(),
                    'executed_at' => $e->executed_at?->toIso8601String(),
                    'completed_at' => $e->completed_at?->toIso8601String(),
                    'last_error' => $e->last_error,
                    'channel' => $e->channel ? ['type' => $e->channel->type] : null,
                    'content_asset' => $e->contentAsset ? [
                        'type' => $e->contentAsset->type,
                        'body' => mb_substr($e->contentAsset->body ?? '', 0, 120),
                    ] : null,
                ])->values()->all(),
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
                'total' => $executions->total(),
            ],
        ]);
    }
}
