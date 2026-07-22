<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\ContentAsset;
use App\Models\MarketingChannel;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Campaign\CampaignChannelSelectionService;
use App\Services\Recommendation\ApprovalService;
use App\Services\Recommendation\ChannelMixPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly CampaignChannelSelectionService $channelSelection,
        private readonly ChannelMixPresenter $channelMixPresenter,
    ) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $pending = Recommendation::with(['decision'])
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn ($r) => $this->formatRecommendation($r));

        $recent = Recommendation::with(['decision'])
            ->where('company_id', $company->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($r) => $this->formatRecommendation($r));

        return Inertia::render('App/Recommendations/Index', [
            'pending' => $pending->values()->all(),
            'recent' => $recent->values()->all(),
        ]);
    }

    public function show(Request $request, Recommendation $recommendation): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($recommendation->company_id !== $company->id, 404);

        $recommendation->load(['decision', 'campaign.contentAssets.channel']);

        $contentAssets = $recommendation->campaign !== null ? $recommendation->campaign->contentAssets : collect();

        $linkedMarketingChannelsByChannelId = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('channel_id')
            ->get()
            ->keyBy('channel_id');

        return Inertia::render('App/Recommendations/Show', [
            'recommendation' => $this->formatRecommendation($recommendation),
            'decision' => $recommendation->decision ? [
                'id' => $recommendation->decision->id,
                'rationale' => $recommendation->decision->rationale,
                'expected_impact' => $recommendation->decision->expected_impact,
                'confidence_score' => $recommendation->decision->confidence_score ?? 0,
                'campaign_type' => $recommendation->decision->campaign_type,
            ] : null,
            'campaign' => $recommendation->campaign ? [
                'id' => $recommendation->campaign->id,
                'title' => $recommendation->campaign->title,
                'campaign_type' => $recommendation->campaign->campaign_type,
                'status' => $recommendation->campaign->status,
            ] : null,
            'channel_mix' => $this->channelMixPresenter->present($company, $recommendation->decision),
            'selected_content_asset_ids' => $contentAssets
                ->filter(fn (ContentAsset $asset): bool => $asset->status !== 'archived')
                ->pluck('id')
                ->values()
                ->all(),
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
        ]);
    }

    public function approve(Request $request, Recommendation $recommendation): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($recommendation->company_id !== $company->id, 404);
        $this->requireApprovalRole($request, $company);

        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $selectedContentAssetIds = $this->selectedContentAssetIdsFromRequest($request, $recommendation, false);

        $this->approvalService->approve(
            $recommendation,
            $user,
            $request->input('notes'),
            $selectedContentAssetIds,
        );

        return redirect()->route('app.recommendations.index')
            ->with('success', 'Approved. Atlas will process your selected channels — connected ones send for real, everything else remains simulated.');
    }

    public function approveEdit(Request $request, Recommendation $recommendation): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($recommendation->company_id !== $company->id, 404);
        $this->requireApprovalRole($request, $company);

        $validated = $request->validate([
            'content_asset_id' => ['required', 'string'],
            'body' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $asset = ContentAsset::where('id', $validated['content_asset_id'])
            ->where('company_id', $company->id)
            ->firstOrFail();

        $selectedContentAssetIds = $this->selectedContentAssetIdsFromRequest($request, $recommendation, true);
        if ($selectedContentAssetIds !== null && ! in_array($asset->id, $selectedContentAssetIds, true)) {
            $selectedContentAssetIds[] = $asset->id;
        }

        $edits = [
            'content_asset_id' => $asset->id,
            'original_body' => $asset->body,
            'edited_body' => $validated['body'],
        ];

        if (isset($validated['title'])) {
            $edits['original_title'] = $asset->title;
            $edits['edited_title'] = $validated['title'];
        }

        $asset->update([
            'body' => $validated['body'],
            'title' => $validated['title'] ?? $asset->title,
        ]);

        $editUser = $request->user();
        abort_unless($editUser instanceof User, 401);

        $this->approvalService->editAndApprove(
            $recommendation,
            $editUser,
            $edits,
            $validated['notes'] ?? null,
            $selectedContentAssetIds,
        );

        return redirect()->route('app.recommendations.index')
            ->with('success', 'Your changes were saved and the recommendation was approved.');
    }

    public function reject(Request $request, Recommendation $recommendation): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($recommendation->company_id !== $company->id, 404);
        $this->requireApprovalRole($request, $company);

        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $rejectUser = $request->user();
        abort_unless($rejectUser instanceof User, 401);

        $this->approvalService->reject($recommendation, $rejectUser, $request->input('notes'));

        return redirect()->route('app.recommendations.index')
            ->with('success', 'Got it. Atlas will keep watching and surface a new recommendation soon.');
    }

    public function selectChannels(Request $request, Recommendation $recommendation): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($recommendation->company_id !== $company->id, 404);
        $this->requireApprovalRole($request, $company);
        abort_if($recommendation->campaign_id === null, 404);
        abort_if($recommendation->status !== 'pending', 403, 'Channels can only be changed while a recommendation is pending.');

        $campaign = Campaign::withoutGlobalScopes()->find($recommendation->campaign_id);
        abort_if($campaign === null, 404);

        $selectedContentAssetIds = $this->selectedContentAssetIdsFromRequest($request, $recommendation, true) ?? [];

        $this->channelSelection->sync($campaign, $selectedContentAssetIds);

        return back()->with('success', 'Delivery channels updated.');
    }

    /** @return array<string, mixed> */
    private function formatRecommendation(Recommendation $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'campaign_type' => $r->campaign_type,
            'rationale_display' => $r->rationale_display ?? [],
            'expected_impact' => $r->expected_impact ?? [],
            'responded_at' => $r->responded_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String() ?? '',
            'decision' => $r->relationLoaded('decision') && $r->decision ? [
                'confidence_score' => $r->decision->confidence_score ?? 0,
            ] : null,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function selectedContentAssetIdsFromRequest(
        Request $request,
        Recommendation $recommendation,
        bool $required,
    ): ?array {
        $rules = [
            'selected_content_asset_ids' => [$required ? 'required' : 'nullable', 'array', 'min:1'],
            'selected_content_asset_ids.*' => ['string'],
        ];

        $validated = $request->validate($rules);

        if (! array_key_exists('selected_content_asset_ids', $validated)) {
            return null;
        }

        $selected = collect($validated['selected_content_asset_ids'] ?? [])
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($recommendation->campaign_id === null) {
            return $selected;
        }

        $validAssetIds = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $recommendation->campaign_id)
            ->pluck('id')
            ->all();

        abort_if(array_diff($selected, $validAssetIds) !== [], 404);

        return $selected;
    }

    private function requireApprovalRole(Request $request, Company $company): void
    {
        $roleUser = $request->user();
        abort_unless($roleUser instanceof User, 401);

        $membership = CompanyMembership::where('user_id', $roleUser->id)
            ->where('company_id', $company->id)
            ->first();

        abort_if(
            ! $membership || ! in_array($membership->role, ['owner', 'admin'], true),
            403,
            'Only company owners and admins can approve or reject recommendations.'
        );
    }
}
