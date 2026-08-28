<?php

namespace App\Billing\Contracts;

use App\Billing\Data\BillingPortalSession;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSession;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\Exceptions\BillingException;
use App\Billing\Exceptions\WebhookVerificationException;

/**
 * The seam between Atlas billing/product logic and the payment provider.
 * Product code depends only on this interface and the value objects in
 * App\Billing\Data — never on the Stripe SDK. Secrets and vendor specifics
 * live entirely inside the concrete implementation.
 */
interface BillingProvider
{
    /**
     * Create-or-return the provider customer id for a company.
     *
     * @throws BillingException
     */
    public function ensureCustomer(string $companyId, string $email, string $name): string;

    /**
     * Open a subscription Checkout Session for an existing customer.
     *
     * @throws BillingException
     */
    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Open a Stripe-hosted billing management portal session.
     *
     * @throws BillingException
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): BillingPortalSession;

    /**
     * Verify a raw webhook request and return the typed, decoded event.
     *
     * @throws WebhookVerificationException on a bad signature or malformed body
     */
    public function parseWebhookEvent(string $payload, string $signatureHeader): StripeWebhookEvent;

    /**
     * Fetch the current state of a subscription straight from the provider.
     *
     * @throws BillingException
     */
    public function fetchSubscription(string $subscriptionId): SubscriptionSnapshot;
}
