<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Execution;
use App\Services\Publishing\ChannelPublishingCapabilityResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublishingController extends Controller
{
    public function __construct(private readonly ChannelPublishingCapabilityResolver $publishingCapabilities) {}

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
        $publishingCapabilities = $this->publishingCapabilities->forChannels(
            $company,
            collect($executions->items())->pluck('channel')->filter()->unique('id')->values(),
        );

        return Inertia::render('App/Publishing', [
            'executions' => [
                'data' => collect($executions->items())->map(function (Execution $e) use ($publishingCapabilities) {
                    return [
                        'id' => $e->id,
                        'status' => $e->status,
                        'scheduled_at' => $e->scheduled_at?->toIso8601String(),
                        'executed_at' => $e->executed_at?->toIso8601String(),
                        'completed_at' => $e->completed_at?->toIso8601String(),
                        'attempts' => $e->attempts,
                        'last_error' => $e->last_error,
                        'result' => $e->result ? [
                            'platform_id' => $e->result['platform_id'] ?? null,
                            'url' => $e->result['url'] ?? null,
                        ] : null,
                        'channel' => $e->channel ? [
                            'type' => $e->channel->type,
                            'marketing_channel' => ['supports_publishing' => $publishingCapabilities->get($e->channel->id, false)],
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
