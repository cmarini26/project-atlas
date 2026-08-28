<?php

namespace App\Http\Controllers\App;

use App\Billing\BillingCheckoutService;
use App\Billing\BillingProfileService;
use App\Billing\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingSettingsController extends Controller
{
    public function __construct(
        private readonly BillingProfileService $profiles,
        private readonly BillingCheckoutService $checkout,
    ) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $profile = $this->profiles->find($company);

        return Inertia::render('App/Settings/Billing', [
            'billing' => [
                'has_customer' => $profile?->hasStripeCustomer() ?? false,
                'has_subscription' => $profile?->hasSubscription() ?? false,
                'status' => $profile?->subscription_status,
                'price_id' => $profile?->price_id,
                'current_period_ends_at' => $profile?->current_period_ends_at?->toIso8601String(),
                'cancel_at_period_end' => $profile?->cancel_at_period_end ?? false,
                'beta_access_override' => $profile?->beta_access_override ?? false,
                'grants_access' => $profile?->grantsAccess() ?? false,
            ],
            'checkout_available' => trim((string) config('billing.price_id')) !== '',
            'can_manage' => $this->isOwnerOrAdmin($request->user(), $company),
            'checkout_result' => $request->string('checkout')->toString() ?: null,
        ]);
    }

    public function portal(Request $request): RedirectResponse|SymfonyResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->requireOwnerOrAdmin($user, $company);

        try {
            $session = $this->checkout->startBillingPortal($company, url('/app/settings/billing'));
        } catch (BillingException $e) {
            Log::warning('BillingSettingsController: could not open billing portal.', [
                'company_id' => $company->id,
                'reason' => $e->getMessage(),
            ]);

            return back()->with('error', 'We could not open the billing portal right now. Please try again shortly.');
        }

        return Inertia::location($session->url);
    }

    private function isOwnerOrAdmin(?User $user, Company $company): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $membership = CompanyMembership::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->first();

        return $membership !== null && in_array($membership->role, ['owner', 'admin'], true);
    }

    private function requireOwnerOrAdmin(User $user, Company $company): void
    {
        abort_unless($this->isOwnerOrAdmin($user, $company), 403, 'Only company owners and admins can manage billing.');
    }
}
