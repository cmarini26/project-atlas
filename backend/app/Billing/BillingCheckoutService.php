<?php

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\BillingPortalSession;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSession;
use App\Billing\Exceptions\BillingException;
use App\Models\Company;
use App\Models\User;

/**
 * Turns "this company wants to subscribe" into a Stripe Checkout Session,
 * linking the company to a Stripe customer along the way. All Stripe
 * specifics stay behind {@see BillingProvider}.
 */
class BillingCheckoutService
{
    public function __construct(
        private readonly BillingProvider $billing,
        private readonly BillingProfileService $profiles,
    ) {}

    /**
     * @throws BillingException when no price is configured or the provider fails
     */
    public function startSubscriptionCheckout(
        Company $company,
        User $actingUser,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        $priceId = trim((string) config('billing.price_id'));

        if ($priceId === '') {
            throw new BillingException('No Atlas subscription price is configured (STRIPE_PRICE_ID).');
        }

        $profile = $this->profiles->forCompany($company);

        $customerId = $profile->stripe_customer_id
            ?? $this->billing->ensureCustomer($company->id, $actingUser->email, $company->name);

        $this->profiles->linkCustomer($company, $customerId);

        return $this->billing->createSubscriptionCheckout(new CheckoutRequest(
            customerId: $customerId,
            priceId: $priceId,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            clientReferenceId: $company->id,
            metadata: ['atlas_company_id' => $company->id],
        ));
    }

    /**
     * Open a Stripe-hosted billing management portal for a company that already
     * has a customer.
     *
     * @throws BillingException when the company has no Stripe customer yet
     */
    public function startBillingPortal(Company $company, string $returnUrl): BillingPortalSession
    {
        $profile = $this->profiles->find($company);

        if ($profile === null || ! $profile->hasStripeCustomer()) {
            throw new BillingException('This company has no Stripe customer yet — start checkout first.');
        }

        return $this->billing->createBillingPortalSession($profile->stripe_customer_id, $returnUrl);
    }
}
