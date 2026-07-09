<?php

namespace App\Http\Controllers\App;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingChannelCapabilityResolver;
use App\Services\MarketingPresence\MarketingPresenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings CRUD for a company's declared marketing channels — a thin
 * delegate to MarketingPresenceService and MarketingChannelCapabilityResolver
 * (Phase 2). No domain logic (validation rules, capability derivation,
 * default suggestion) is reimplemented here or in the Vue page; both
 * services own their respective concerns exactly as they do for the
 * onboarding step (Phase 3).
 */
class MarketingPresenceController extends Controller
{
    public function __construct(
        private readonly MarketingPresenceService $marketingPresenceService,
        private readonly MarketingChannelCapabilityResolver $capabilityResolver,
    ) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        $importanceOrder = array_flip(MarketingChannelImportance::values());
        $statusOrder = array_flip(MarketingChannelStatus::values());

        $channels = MarketingChannel::where('company_id', $company->id)
            ->with('channel')
            ->get()
            ->sort(fn (MarketingChannel $a, MarketingChannel $b): int => [
                $importanceOrder[$a->importance->value],
                $statusOrder[$a->status->value],
            ] <=> [
                $importanceOrder[$b->importance->value],
                $statusOrder[$b->status->value],
            ])
            ->values();

        return Inertia::render('App/Settings/MarketingPresence/Index', [
            'channels' => $channels->map(fn (MarketingChannel $channel) => $this->serialize($channel))->all(),
            'statuses' => MarketingChannelStatus::values(),
            'importances' => MarketingChannelImportance::values(),
            'objectives' => MarketingChannelObjective::values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(MarketingChannelType::class)],
            'display_name' => ['required', 'string', 'max:255'],
            'handle_or_url' => ['nullable', 'string', 'max:255'],
        ]);

        $this->marketingPresenceService->declare($company, $validated);

        return back()->with('success', 'Marketing channel added.');
    }

    public function update(Request $request, MarketingChannel $marketingChannel): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($marketingChannel->company_id !== $company->id, 404);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(MarketingChannelStatus::class)],
            'importance' => ['sometimes', Rule::enum(MarketingChannelImportance::class)],
            'objective' => ['sometimes', 'array', 'min:1'],
            'objective.*' => [Rule::enum(MarketingChannelObjective::class)],
        ]);

        $this->marketingPresenceService->update($marketingChannel, $validated);

        return back()->with('success', 'Marketing channel updated.');
    }

    /**
     * "Delete" is a soft, reversible disable — sets status: inactive, never
     * removes the row. See specs/core/marketing-presence.md §2 (no
     * soft-delete column) and the plan's Phase 4 note. The route stays
     * RESTful for convention; the behavior is documented here.
     */
    public function destroy(Request $request, MarketingChannel $marketingChannel): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($marketingChannel->company_id !== $company->id, 404);

        $this->marketingPresenceService->disable($marketingChannel);

        return back()->with('success', 'Marketing channel disabled.');
    }

    /** @return array<string, mixed> */
    private function serialize(MarketingChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'display_name' => $channel->display_name,
            'handle_or_url' => $channel->handle_or_url,
            'status' => $channel->status->value,
            'importance' => $channel->importance->value,
            'objective' => $channel->objective,
            'capability' => $this->capabilityResolver->resolve($channel)->value,
        ];
    }

    private function company(Request $request): Company
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        return $company;
    }
}
