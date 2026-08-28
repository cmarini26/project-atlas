<?php

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified Stripe webhook event to Atlas billing state. Every path
 * is idempotent — Stripe retries and re-orders deliveries.
 *
 * Handled events:
 *   - checkout.session.completed          link customer + mirror the new subscription
 *   - customer.subscription.created       mirror subscription state
 *   - customer.subscription.updated       mirror subscription state
 *   - customer.subscription.deleted       mirror subscription state (status → canceled)
 *
 * Anything else is acknowledged and ignored.
 */
class StripeWebhookProcessor
{
    public function __construct(
        private readonly BillingProvider $billing,
        private readonly BillingProfileService $profiles,
    ) {}

    public function process(StripeWebhookEvent $event): void
    {
        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event->object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->onSubscriptionChanged($event->object),
            default => Log::info('StripeWebhookProcessor: ignoring event.', ['type' => $event->type]),
        };
    }

    /** @param array<string, mixed> $session */
    private function onCheckoutCompleted(array $session): void
    {
        $companyId = (string) ($session['client_reference_id'] ?? '');
        $company = $companyId !== '' ? Company::withoutGlobalScopes()->find($companyId) : null;

        if ($company === null) {
            Log::warning('StripeWebhookProcessor: checkout.session.completed for an unknown company.', [
                'client_reference_id' => $companyId,
            ]);

            return;
        }

        $customerId = (string) ($session['customer'] ?? '');
        if ($customerId !== '') {
            $this->profiles->linkCustomer($company, $customerId);
        }

        $subscriptionId = (string) ($session['subscription'] ?? '');
        if ($subscriptionId !== '') {
            $this->profiles->applySubscriptionSnapshot(
                $company,
                $this->billing->fetchSubscription($subscriptionId),
            );
        }
    }

    /** @param array<string, mixed> $subscription */
    private function onSubscriptionChanged(array $subscription): void
    {
        $snapshot = SubscriptionSnapshot::fromStripeArray($subscription);
        $company = $this->resolveCompany($subscription, $snapshot);

        if ($company === null) {
            Log::warning('StripeWebhookProcessor: subscription event for an unknown company.', [
                'subscription_id' => $snapshot->id,
                'customer' => $snapshot->customerId,
            ]);

            return;
        }

        $this->profiles->applySubscriptionSnapshot($company, $snapshot);
    }

    /** @param array<string, mixed> $subscription */
    private function resolveCompany(array $subscription, SubscriptionSnapshot $snapshot): ?Company
    {
        $metaCompanyId = (string) ($subscription['metadata']['atlas_company_id'] ?? '');

        if ($metaCompanyId !== '' && ($company = Company::withoutGlobalScopes()->find($metaCompanyId)) !== null) {
            return $company;
        }

        $profile = $this->profiles->findByStripeCustomerId($snapshot->customerId);

        return $profile?->company()->withoutGlobalScopes()->first();
    }
}
