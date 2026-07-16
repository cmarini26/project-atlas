<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Execution;
use App\Models\MarketingChannel;
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

        // Built once per request and keyed by channel_id so each execution
        // below is an O(1) lookup, not a per-row query — see
        // RecommendationController::show() for the same established pattern.
        $linkedMarketingChannelsByChannelId = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('channel_id')
            ->get()
            ->keyBy('channel_id');

        return Inertia::render('App/Publishing', [
            'executions' => [
                'data' => collect($executions->items())->map(function (Execution $e) use ($linkedMarketingChannelsByChannelId) {
                    $linked = $e->channel !== null ? $linkedMarketingChannelsByChannelId->get($e->channel->id) : null;

                    return [
                        'id' => $e->id,
                        'status' => $e->status,
                        'scheduled_at' => $e->scheduled_at?->toIso8601String(),
                        'executed_at' => $e->executed_at?->toIso8601String(),
                        'completed_at' => $e->completed_at?->toIso8601String(),
                        'last_error' => $e->last_error,
                        'channel' => $e->channel ? [
                            'type' => $e->channel->type,
                            'marketing_channel' => $linked !== null
                                ? ['supports_publishing' => (bool) $linked->supports_publishing]
                                : null,
                        ] : null,
                        'content_asset' => $e->contentAsset ? [
                            'type' => $e->contentAsset->type,
                            'body' => mb_substr($e->contentAsset->body ?? '', 0, 120),
                        ] : null,
                    ];
                })->values()->all(),
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
                'total' => $executions->total(),
            ],
        ]);
    }
}
