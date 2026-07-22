<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\EmailAudience;
use App\Models\EmailRecipientSnapshot;
use App\Models\Execution;
use App\Models\MarketingChannel;
use App\Services\Campaign\CampaignChannelSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignChannelSelectionService $channelSelection,
    ) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $campaigns = Campaign::where('company_id', $company->id)
            ->latest()
            ->paginate(15);

        return Inertia::render('App/Campaigns/Index', [
            'campaigns' => [
                'data' => collect($campaigns->items())->map(fn (Campaign $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'campaign_type' => $c->campaign_type,
                    'status' => $c->status,
                    'created_at' => $c->created_at?->toIso8601String() ?? '',
                    'completed_at' => $c->completed_at?->toIso8601String(),
                ])->values()->all(),
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($campaign->company_id !== $company->id, 404);

        $campaign->load(['decision', 'kpiSnapshots']);

        $contentAssets = ContentAsset::with('channel')
            ->where('company_id', $company->id)
            ->where('campaign_id', $campaign->id)
            ->get();

        $executions = Execution::with('channel')
            ->where('company_id', $company->id)
            ->where('campaign_id', $campaign->id)
            ->get();

        // Built once per request and keyed by channel_id so each content
        // asset/execution below is an O(1) lookup, not a per-row query — see
        // RecommendationController::show() for the same established pattern.
        $linkedMarketingChannelsByChannelId = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('channel_id')
            ->get()
            ->keyBy('channel_id');

        $kpiSnapshot = $campaign->kpiSnapshots()
            ->where('snapshot_type', 'final')
            ->latest('snapshotted_at')
            ->first()
            ?? $campaign->kpiSnapshots()
                ->where('snapshot_type', 'interim')
                ->latest('snapshotted_at')
                ->first();

        // Whether Email is genuinely connected for this company — resolved
        // via the existing capability-truth signal (supports_publishing on
        // the declared `email` MarketingChannel, kept in sync by
        // SettingsController::connectEmail()/CheckChannelHealth), the same
        // linked-marketing-channel shape ChannelCapabilityBadge already
        // consumes elsewhere on this page. Never hardcoded.
        $emailMarketingChannel = MarketingChannel::where('company_id', $company->id)
            ->where('type', 'email')
            ->first();

        $audiences = EmailAudience::where('company_id', $company->id)
            ->where('status', 'active')
            ->withCount('members')
            ->orderBy('name')
            ->get();

        $selectedAudience = $campaign->email_audience_id !== null
            ? EmailAudience::withCount('members')->find($campaign->email_audience_id)
            : null;

        // Aggregate counts only — never the recipient rows themselves.
        // EmailRecipientSnapshot.email is real PII (a real contact's
        // address); this page has no privileged per-recipient detail view
        // designed to hold it, so only "how many" is exposed here, never
        // "who." `null` means no audience-targeted send has ever been
        // queued for this campaign (distinct from all-zero, which can't
        // actually happen — see EmailAudienceService::
        // snapshotRecipientsForExecution(), which never persists zero rows
        // without EmailPublisher having already rejected the empty case).
        $recipientOutcomeCounts = EmailRecipientSnapshot::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $recipientOutcomes = $recipientOutcomeCounts->isEmpty() ? null : [
            'pending' => (int) ($recipientOutcomeCounts['pending'] ?? 0),
            'accepted' => (int) ($recipientOutcomeCounts['sent'] ?? 0),
            'failed' => (int) ($recipientOutcomeCounts['failed'] ?? 0),
            'skipped' => (int) ($recipientOutcomeCounts['skipped'] ?? 0),
            'total' => (int) $recipientOutcomeCounts->sum(),
        ];

        $canEditChannelSelection = in_array($campaign->status, ['draft', 'approved'], true)
            && $executions->isEmpty();

        return Inertia::render('App/Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'campaign_type' => $campaign->campaign_type,
                'status' => $campaign->status,
                'created_at' => $campaign->created_at?->toIso8601String() ?? '',
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'blueprint' => $campaign->blueprint,
            ],
            'selected_content_asset_ids' => $contentAssets
                ->filter(fn (ContentAsset $asset): bool => $asset->status !== 'archived')
                ->pluck('id')
                ->values()
                ->all(),
            'can_edit_channel_selection' => $canEditChannelSelection,
            'content_assets' => $contentAssets->map(function (ContentAsset $a) use ($linkedMarketingChannelsByChannelId) {
                $linked = $a->channel !== null ? $linkedMarketingChannelsByChannelId->get($a->channel->id) : null;

                return [
                    'id' => $a->id,
                    'type' => $a->type,
                    'body' => $a->body,
                    'title' => $a->title,
                    'status' => $a->status,
                    'media' => $a->media,
                    'metadata' => $a->metadata ?? [],
                    'channel' => $a->channel ? [
                        'type' => $a->channel->type,
                        'marketing_channel' => $linked !== null
                            ? ['supports_publishing' => (bool) $linked->supports_publishing]
                            : null,
                    ] : null,
                ];
            })->values()->all(),
            'executions' => $executions->map(function (Execution $e) use ($linkedMarketingChannelsByChannelId) {
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
                ];
            })->values()->all(),
            'kpi_snapshot' => $kpiSnapshot ? [
                'id' => $kpiSnapshot->id,
                'snapshot_type' => $kpiSnapshot->snapshot_type,
                'actual_kpis' => $kpiSnapshot->actual_kpis ?? [],
                'performance_rating' => $kpiSnapshot->performance_rating,
                'snapshotted_at' => $kpiSnapshot->snapshotted_at->toIso8601String(),
            ] : null,
            'decision' => $campaign->decision ? [
                'rationale' => $campaign->decision->rationale,
                'expected_impact' => $campaign->decision->expected_impact,
                'confidence_score' => $campaign->decision->confidence_score ?? 0,
            ] : null,
            'email_audience_selector' => [
                'audiences' => $audiences->map(fn (EmailAudience $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'member_count' => $a->members_count,
                ])->values()->all(),
                'selected' => $selectedAudience ? [
                    'id' => $selectedAudience->id,
                    'name' => $selectedAudience->name,
                    'member_count' => $selectedAudience->members_count,
                ] : null,
                'linked_marketing_channel' => $emailMarketingChannel ? [
                    'supports_publishing' => (bool) $emailMarketingChannel->supports_publishing,
                ] : null,
                'recipient_outcomes' => $recipientOutcomes,
            ],
        ]);
    }

    /**
     * Assigns (or clears) which audience an Email campaign targets. Kept
     * generic rather than restricted to a specific campaign_type — this
     * page's own frontend only shows the control when it's relevant, per
     * the same "don't build arbitrary boolean segmentation" scope this
     * feature was asked to stay within.
     */
    public function selectEmailAudience(Request $request, Campaign $campaign): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($campaign->company_id !== $company->id, 404);

        $validated = $request->validate([
            'email_audience_id' => ['nullable', 'string'],
        ]);

        $audienceId = $validated['email_audience_id'] ?? null;

        if ($audienceId !== null) {
            $audience = EmailAudience::where('company_id', $company->id)->find($audienceId);
            abort_if($audience === null, 404);
        }

        $campaign->update(['email_audience_id' => $audienceId]);

        return back()->with('success', $audienceId !== null ? 'Audience selected.' : 'Audience cleared.');
    }

    public function selectChannels(Request $request, Campaign $campaign): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($campaign->company_id !== $company->id, 404);
        abort_if(! in_array($campaign->status, ['draft', 'approved'], true), 403, 'Channels can only be changed before publishing starts.');
        abort_if(Execution::withoutGlobalScopes()->where('campaign_id', $campaign->id)->exists(), 403, 'Channels can only be changed before executions are queued.');

        $selectedContentAssetIds = $this->selectedContentAssetIdsFromRequest($request, $campaign);
        $this->channelSelection->sync($campaign, $selectedContentAssetIds);

        return back()->with('success', 'Campaign channels updated.');
    }

    /**
     * @return list<string>
     */
    private function selectedContentAssetIdsFromRequest(Request $request, Campaign $campaign): array
    {
        $validated = $request->validate([
            'selected_content_asset_ids' => ['required', 'array', 'min:1'],
            'selected_content_asset_ids.*' => ['string'],
        ]);

        $selected = collect($validated['selected_content_asset_ids'])
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $validAssetIds = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->pluck('id')
            ->all();

        abort_if(array_diff($selected, $validAssetIds) !== [], 404);

        return $selected;
    }
}
