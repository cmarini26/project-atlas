<?php

namespace App\Http\Controllers;

use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\Observatory\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly CompanyService $companyService,
        private readonly IntegrationService $integrationService,
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

        // Integration submitted — wait for analysis to complete
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

        $integration = $this->integrationService->create(
            $company,
            'website_crawl',
            ['url' => $validated['website_url']]
        );

        // Run the first sync inline so observations are recorded immediately,
        // before the user reaches the status page. Subsequent scheduled syncs
        // are dispatched via the queue and processed by workers.
        try {
            SyncIntegration::dispatchSync($integration);
        } catch (Throwable $e) {
            $integration->markAsError($e->getMessage());
            report($e);
        }

        $request->session()->forget('onboarding_company_id');

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
}
