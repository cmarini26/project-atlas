<?php

namespace App\Http\Controllers;

use App\AI\Exceptions\AiProviderOverloadedException;
use App\Enums\MarketingChannelType;
use App\Jobs\SyncIntegration;
use App\Models\Channel;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Observatory\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OnboardingController extends Controller
{
    /**
     * Human-readable display names for the onboarding marketing-presence
     * step's checklist — one per MarketingChannelType. Kept here rather than
     * on the enum since these are onboarding-copy concerns, not a domain fact.
     *
     * @var array<string, string>
     */
    private const CHANNEL_LABELS = [
        'website' => 'Website',
        'email' => 'Email Newsletter',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'x' => 'X',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'google_business_profile' => 'Google Business Profile',
        'events' => 'Events',
        'print' => 'Print',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly CompanyService $companyService,
        private readonly IntegrationService $integrationService,
        private readonly MarketingPresenceService $marketingPresenceService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->first();

        if (! $membership) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 1]);
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        // Company exists but no integration yet — collect website URL
        if (! Integration::where('company_id', $company->id)->exists()) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 2]);
        }

        // Website connected but marketing presence not declared yet — the
        // final onboarding step, so it's captured before the status page's
        // "Atlas is now learning" experience begins.
        if (! MarketingChannel::where('company_id', $company->id)->exists()) {
            return Inertia::render('Onboarding/Index', ['initial_step' => 3]);
        }

        // Both submitted — wait for analysis to complete
        return redirect()->route('onboarding.status');
    }

    public function createCompany(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
        ]);

        $company = $this->companyService->create(
            $user,
            [
                'name' => $validated['name'],
                'industry' => $validated['industry'] ?? null,
            ]
        );

        $request->session()->put('onboarding_company_id', $company->id);

        return redirect()->route('onboarding');
    }

    public function createIntegration(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'website_url' => ['required', 'url', 'max:500'],
        ]);

        $companyId = $request->session()->get('onboarding_company_id');
        $membership = CompanyMembership::with('company')
            ->where('user_id', $user->id)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first();

        if (! $membership) {
            return redirect()->route('onboarding');
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        $company->update(['website_url' => $validated['website_url']]);

        // Reuse the company's website integration on resubmits ("try a
        // different URL", double-clicks) instead of creating a new row each
        // time. Combined with SyncIntegration's ShouldBeUnique (keyed on the
        // integration id), this caps queued crawls + AI pipeline runs at one
        // per company — repeat submits cannot stack AI spend.
        $integration = Integration::where('company_id', $company->id)
            ->where('type', 'website_crawl')
            ->first();

        if ($integration !== null) {
            $integration->update([
                'config' => ['url' => $validated['website_url']],
                'status' => 'active',
                'last_error' => null,
            ]);
        } else {
            $integration = $this->integrationService->create(
                $company,
                'website_crawl',
                ['url' => $validated['website_url']]
            );
        }

        // Seed a default blog channel so DecisionEngine has at least one
        // active channel to commit a Decision against. Users can add real
        // connected channels (email, social) through Settings later.
        if (! Channel::where('company_id', $company->id)->exists()) {
            Channel::create([
                'company_id' => $company->id,
                'type' => 'blog',
                'name' => 'Blog',
                'is_active' => true,
            ]);
        }

        // Queue the first sync — never run it inline. The crawl plus the AI
        // pipeline (facts → opportunity → rationale → campaign → content) can
        // take minutes with a real provider; running it in the request caused
        // 502s behind PHP-FPM/Herd. The status page polls progress while the
        // worker processes it. The try/catch only matters for environments
        // still using QUEUE_CONNECTION=sync, where dispatch() runs inline.
        try {
            SyncIntegration::dispatch($integration);
        } catch (AiProviderOverloadedException $e) {
            // The crawl succeeded — only the AI analysis is waiting on the
            // provider. The observation is left in 'retrying' and the status
            // endpoint re-dispatches it, so don't mark the integration error.
            report($e);
        } catch (Throwable $e) {
            $integration->markAsError($e->getMessage());
            report($e);
        }

        $request->session()->forget('onboarding_company_id');

        // Not straight to the status page — the marketing-presence step
        // still needs to run first. index() re-evaluates and shows step 3.
        return redirect()->route('onboarding');
    }

    /**
     * Final onboarding step: declare which marketing channels the business
     * already uses today. Creates one unlinked, unconnected MarketingChannel
     * per selection via MarketingPresenceService::declare() — no Channel
     * record, no integration, no API connection. Runs before the redirect to
     * the status page, so Atlas knows the company's declared marketing
     * presence before the Business Brain pipeline's asynchronous learning
     * step meaningfully begins.
     */
    public function saveMarketingPresence(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->first();

        if (! $membership) {
            return redirect()->route('onboarding');
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        $validated = $request->validate([
            'channels' => ['present', 'array'],
            'channels.*' => [Rule::in(MarketingChannelType::values())],
        ]);

        foreach ($validated['channels'] as $type) {
            // Idempotent against back-button resubmits and double-clicks —
            // declare() itself happily allows duplicates, but a repeat
            // submission of this same step shouldn't create them.
            if (MarketingChannel::where('company_id', $company->id)->where('type', $type)->exists()) {
                continue;
            }

            $this->marketingPresenceService->declare($company, [
                'type' => $type,
                'display_name' => self::CHANNEL_LABELS[$type] ?? ucfirst((string) $type),
            ]);
        }

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

    /**
     * Re-dispatch the existing website_crawl integration after a transient
     * failure (site was briefly unreachable, AI provider hiccup) without
     * making the user re-type the URL they already gave us.
     */
    public function retry(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->first();

        if (! $membership) {
            return redirect()->route('onboarding');
        }

        $company = $membership->company;
        abort_unless($company instanceof Company, 404);

        $integration = Integration::where('company_id', $company->id)
            ->where('type', 'website_crawl')
            ->first();

        if (! $integration) {
            return redirect()->route('onboarding');
        }

        $integration->update(['status' => 'active', 'last_error' => null]);

        try {
            SyncIntegration::dispatch($integration);
        } catch (AiProviderOverloadedException $e) {
            report($e);
        } catch (Throwable $e) {
            $integration->markAsError($e->getMessage());
            report($e);
        }

        return redirect()->route('onboarding.status');
    }
}
