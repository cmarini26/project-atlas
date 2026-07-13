<?php

namespace App\Http\Controllers;

use App\Domain\Onboarding\AssetDetailRequirements;
use App\Enums\BusinessGoal;
use App\Enums\MarketingChannelType;
use App\Enums\MarketingFrequency;
use App\Enums\MarketingOwner;
use App\Enums\PrimaryCallToAction;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MarketingChannel;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\Onboarding\OnboardingAssetService;
use App\Services\Onboarding\OnboardingProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 15 Phase 1 — Business Discovery Onboarding (UI + data
 * collection only). Replaces the old website-first, single-integration
 * wizard: seven steps — Welcome, Company, Business Goals, Marketing
 * Assets, Asset Details, Marketing Preferences, Discovery Placeholder —
 * none of which dispatch a connector, touch the Observation pipeline, or
 * modify Business Brain / Marketing Health / the Opportunity or Decision
 * Engine. See docs/specs/Business-Discovery-Onboarding.md.
 */
class OnboardingController extends Controller
{
    /**
     * The subset of MarketingChannelType offered as a Marketing Assets
     * card in this wizard — deliberately narrower than the full enum
     * (excludes TikTok, Other), matching the exact card list specified for
     * this phase.
     *
     * @var list<string>
     */
    private const ASSET_CARD_TYPES = [
        'website', 'google_business_profile', 'instagram', 'facebook',
        'linkedin', 'x', 'youtube', 'email', 'events', 'print',
    ];

    public function __construct(
        private readonly CompanyService $companyService,
        private readonly OnboardingProfileService $onboardingProfileService,
        private readonly OnboardingAssetService $onboardingAssetService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->first();

        if (! $membership) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 1, 'enabled_assets' => []]);
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        $profile = $this->onboardingProfileService->for($company);

        if ($profile === null || empty($profile->business_goals)) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 3, 'enabled_assets' => []]);
        }

        $channels = MarketingChannel::where('company_id', $company->id)->get();

        if ($channels->isEmpty()) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 4, 'enabled_assets' => []]);
        }

        $enabledAssets = $this->serializeAssets($channels);

        $needsDetails = $channels->contains(function (MarketingChannel $c): bool {
            /** @var array<string, mixed> $metadata */
            $metadata = $c->metadata ?? [];

            return ! AssetDetailRequirements::isSatisfied($c->type->value, $c->handle_or_url, $metadata);
        });

        if ($needsDetails) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 5, 'enabled_assets' => $enabledAssets]);
        }

        if ($profile->marketing_frequency === null) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 6, 'enabled_assets' => $enabledAssets]);
        }

        if (! $profile->isComplete()) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 7, 'enabled_assets' => $enabledAssets]);
        }

        return redirect()->route('onboarding.status');
    }

    public function saveCompany(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // A membership already existing means this step is already done —
        // never create a second company for the same user on a resubmit
        // (double-click, back-button).
        if (CompanyMembership::where('user_id', $user->id)->exists()) {
            return redirect()->route('onboarding');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->companyService->create($user, $validated);

        return redirect()->route('onboarding');
    }

    public function saveGoals(Request $request): RedirectResponse
    {
        if (($company = $this->findCompany($request)) === null) {
            return redirect()->route('onboarding');
        }

        $validated = $request->validate([
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => [Rule::enum(BusinessGoal::class)],
        ]);

        $this->onboardingProfileService->saveGoals($company, $validated['goals']);

        return redirect()->route('onboarding');
    }

    public function saveAssets(Request $request): RedirectResponse
    {
        if (($company = $this->findCompany($request)) === null) {
            return redirect()->route('onboarding');
        }

        $validated = $request->validate([
            'enabled' => ['required', 'array', 'min:1'],
            'enabled.*' => [Rule::in(self::ASSET_CARD_TYPES)],
            'primary' => ['sometimes', 'array', 'max:3'],
            'primary.*' => [Rule::in(self::ASSET_CARD_TYPES)],
        ]);

        $enabled = $validated['enabled'];
        $primary = $validated['primary'] ?? [];

        $invalidPrimary = array_diff($primary, $enabled);

        if ($invalidPrimary !== []) {
            throw ValidationException::withMessages([
                'primary' => 'You can only mark an enabled asset as primary.',
            ]);
        }

        $this->onboardingAssetService->syncEnabledAssets($company, $enabled, $primary);

        return redirect()->route('onboarding');
    }

    public function saveAssetDetails(Request $request): RedirectResponse
    {
        if (($company = $this->findCompany($request)) === null) {
            return redirect()->route('onboarding');
        }

        $channels = MarketingChannel::where('company_id', $company->id)->get();

        $validated = $request->validate([
            'assets' => ['required', 'array'],
        ]);

        $assets = $validated['assets'];
        $errors = [];

        foreach ($channels as $channel) {
            $type = $channel->type->value;

            if (! in_array($type, AssetDetailRequirements::REQUIRES_DETAILS, true)) {
                continue;
            }

            $rules = $type === 'website'
                ? ['url' => ['required', 'url', 'max:500'], 'platform' => ['required', 'string']]
                : [($type === 'google_business_profile' ? 'business_name_or_url' : 'url') => ['required', 'string', 'max:500']];

            $validator = Validator::make($assets[$type] ?? [], $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["assets.{$type}.{$field}"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->onboardingAssetService->saveAssetDetails($company, $assets);

        return redirect()->route('onboarding');
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        if (($company = $this->findCompany($request)) === null) {
            return redirect()->route('onboarding');
        }

        $validated = $request->validate([
            'marketing_frequency' => ['required', Rule::enum(MarketingFrequency::class)],
            'marketing_owner' => ['required', Rule::enum(MarketingOwner::class)],
            'is_seasonal' => ['required', 'boolean'],
            'seasonal_months' => ['required_if:is_seasonal,true', 'array'],
            'seasonal_months.*' => ['integer', 'between:1,12'],
            'primary_cta' => ['required', Rule::enum(PrimaryCallToAction::class)],
        ]);

        $this->onboardingProfileService->savePreferences($company, $validated);

        return redirect()->route('onboarding');
    }

    public function finish(Request $request): RedirectResponse
    {
        if (($company = $this->findCompany($request)) === null) {
            return redirect()->route('onboarding');
        }

        $this->onboardingProfileService->markCompleted($company);

        return redirect()->route('onboarding.status');
    }

    public function status(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::where('user_id', $user->id)->first();

        if (! $membership) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Onboarding/Status');
    }

    private function findCompany(Request $request): ?Company
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->first();

        if ($membership === null) {
            return null;
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    /**
     * @param  Collection<int, MarketingChannel>  $channels
     * @return list<array<string, mixed>>
     */
    private function serializeAssets(Collection $channels): array
    {
        return array_values($channels->map(function (MarketingChannel $c): array {
            /** @var array<string, mixed> $metadata */
            $metadata = $c->metadata ?? [];

            return [
                'type' => $c->type->value,
                'label' => MarketingChannelType::from($c->type->value)->label(),
                'importance' => $c->importance->value,
                'handle_or_url' => $c->handle_or_url,
                'metadata' => $metadata,
            ];
        })->all());
    }
}
