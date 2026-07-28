<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SourceAsset;
use App\Services\SourceAssets\SourceAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SourceAssetController extends Controller
{
    public function __construct(private readonly SourceAssetService $assets) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $assets = SourceAsset::where('company_id', $company->id)
            ->withCount('opportunities')
            ->latest()
            ->get()
            ->map(fn (SourceAsset $asset): array => [
                'id' => $asset->id,
                'type' => $asset->type,
                'title' => $asset->title,
                'description' => $asset->description,
                'source_url' => $asset->source_url,
                'media_url' => $asset->media_path ? asset("storage/{$asset->media_path}") : null,
                'metadata' => $asset->metadata ?? [],
                'status' => $asset->status,
                'processing_error' => $asset->processing_error,
                'starts_at' => $asset->starts_at?->toIso8601String(),
                'ends_at' => $asset->ends_at?->toIso8601String(),
                'processed_at' => $asset->processed_at?->toIso8601String(),
                'opportunities_count' => $asset->opportunities_count,
            ]);

        return Inertia::render('App/Assets/Index', [
            'assets' => $assets->values()->all(),
            'types' => SourceAsset::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assets->create($this->company($request), $this->validated($request));

        return back()->with('success', 'Asset added. Atlas is analyzing it now.');
    }

    public function update(Request $request, SourceAsset $sourceAsset): RedirectResponse
    {
        $this->authorizeAsset($request, $sourceAsset);
        $this->assets->update($sourceAsset, $this->validated($request));

        return back()->with('success', 'Asset updated and queued for fresh analysis.');
    }

    public function retry(Request $request, SourceAsset $sourceAsset): RedirectResponse
    {
        $this->authorizeAsset($request, $sourceAsset);
        $this->assets->retry($sourceAsset);

        return back()->with('success', 'Asset analysis queued again.');
    }

    public function destroy(Request $request, SourceAsset $sourceAsset): RedirectResponse
    {
        $this->authorizeAsset($request, $sourceAsset);
        $this->assets->archive($sourceAsset);

        return back()->with('success', 'Asset archived.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(SourceAsset::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,pdf', 'max:10240'],
            'metadata' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function authorizeAsset(Request $request, SourceAsset $asset): void
    {
        abort_if($asset->company_id !== $this->company($request)->id, 404);
    }

    private function company(Request $request): Company
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        return $company;
    }
}
