<?php

namespace App\Http\Controllers\App;

use App\Billing\BillingCheckoutService;
use App\Billing\Exceptions\BillingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BillingCheckoutController extends Controller
{
    public function __construct(private readonly BillingCheckoutService $checkout) {}

    public function store(Request $request): RedirectResponse|Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->requireOwnerOrAdmin($user, $company);

        try {
            $session = $this->checkout->startSubscriptionCheckout(
                $company,
                $user,
                url('/app/settings/billing?checkout=success'),
                url('/app/settings/billing?checkout=cancelled'),
            );
        } catch (BillingException $e) {
            Log::warning('BillingCheckoutController: could not start checkout.', [
                'company_id' => $company->id,
                'reason' => $e->getMessage(),
            ]);

            return back()->with('error', 'We could not start checkout right now. Please try again, or contact support if it keeps happening.');
        }

        // Full-page redirect to the Stripe-hosted checkout page.
        return Inertia::location($session->url);
    }

    private function requireOwnerOrAdmin(User $user, Company $company): void
    {
        $membership = CompanyMembership::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->first();

        abort_if(
            $membership === null || ! in_array($membership->role, ['owner', 'admin'], true),
            403,
            'Only company owners and admins can manage billing.',
        );
    }
}
