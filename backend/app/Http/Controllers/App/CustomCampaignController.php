<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Company;
use App\Models\SourceAsset;
use App\Services\Campaign\CustomCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomCampaignController extends Controller
{
    public function __construct(private readonly CustomCampaignService $campaigns) {}

    public function create(Request $request): Response
    {
        $company = $this->company($request);

        $assets = SourceAsset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('status', 'ready')
            ->latest()
            ->get()
            ->map(fn (SourceAsset $asset): array => [
                'id' => $asset->id,
                'type' => $asset->type,
                'title' => $asset->title,
                'description' => $asset->description,
                'media_url' => $asset->media_path ? asset("storage/{$asset->media_path}") : null,
                'media_mime_type' => $asset->media_mime_type,
            ]);

        $channels = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'type' => $channel->type,
                'name' => $channel->name,
            ]);

        $requestedAssetId = $request->string('asset_id')->toString();
        $initialAssetIds = $assets->contains(fn (array $asset): bool => $asset['id'] === $requestedAssetId)
            ? [$requestedAssetId]
            : [];

        return Inertia::render('App/Campaigns/Create', [
            'assets' => $assets->values()->all(),
            'channels' => $channels->values()->all(),
            'initial_asset_ids' => $initialAssetIds,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'min:3', 'max:255'],
            'goal' => ['required', Rule::in(['awareness', 'conversion', 're_engagement'])],
            'objective' => ['required', 'string', 'min:20', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:1000'],
            'guidance' => ['nullable', 'string', 'max:2000'],
            // Source assets are optional enrichment: a prompt alone is enough to
            // compose a campaign. Any IDs supplied are still ownership-checked in
            // CustomCampaignService::compose().
            'source_asset_ids' => ['nullable', 'array', 'max:20'],
            'source_asset_ids.*' => ['required', 'string', 'distinct'],
            // Opt in to generated imagery even when supplying your own assets.
            // Ignored (always on) when no assets are supplied.
            'generate_imagery' => ['nullable', 'boolean'],
            'channel_ids' => ['required', 'array', 'min:1', 'max:8'],
            'channel_ids.*' => ['required', 'string', 'distinct'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $decision = $this->campaigns->compose($this->company($request), $validated);
        $recommendation = $decision->recommendation()->first();

        if ($recommendation !== null) {
            return redirect()->route('app.recommendations.show', $recommendation)
                ->with('success', 'Your custom campaign is ready to review.');
        }

        return redirect()->route('app.campaigns.index')
            ->with('success', 'Atlas is preparing your custom campaign. It will appear here shortly.');
    }

    private function company(Request $request): Company
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        return $company;
    }
}
