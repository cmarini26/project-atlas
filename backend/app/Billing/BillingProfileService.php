<?php

namespace App\Billing;

use App\Billing\Data\SubscriptionSnapshot;
use App\Models\BillingProfile;
use App\Models\Company;

/**
 * The single writer of Atlas's billing truth. Everything that changes a
 * {@see BillingProfile} — checkout linkage (CM-74), webhook sync (CM-75),
 * operator overrides — goes through here so the invariants stay in one place.
 */
class BillingProfileService
{
    public function forCompany(Company $company): BillingProfile
    {
        return BillingProfile::firstOrCreate(['company_id' => $company->id]);
    }

    public function find(Company $company): ?BillingProfile
    {
        return BillingProfile::where('company_id', $company->id)->first();
    }

    public function findByStripeCustomerId(string $customerId): ?BillingProfile
    {
        return $customerId === '' ? null : BillingProfile::where('stripe_customer_id', $customerId)->first();
    }

    public function linkCustomer(Company $company, string $customerId): BillingProfile
    {
        $profile = $this->forCompany($company);

        if ($profile->stripe_customer_id !== $customerId) {
            $profile->stripe_customer_id = $customerId;
            $profile->save();
        }

        return $profile;
    }

    /**
     * Mirror a Stripe subscription snapshot onto the profile. Idempotent —
     * webhooks arrive out of order and more than once.
     */
    public function applySubscriptionSnapshot(Company $company, SubscriptionSnapshot $snapshot): BillingProfile
    {
        $profile = $this->forCompany($company);

        $profile->fill([
            'stripe_customer_id' => $profile->stripe_customer_id ?? $snapshot->customerId ?: null,
            'stripe_subscription_id' => $snapshot->id,
            'subscription_status' => $snapshot->status,
            'price_id' => $snapshot->priceId,
            'current_period_ends_at' => $snapshot->currentPeriodEnd,
            'cancel_at_period_end' => $snapshot->cancelAtPeriodEnd,
        ]);
        $profile->save();

        return $profile;
    }

    public function setBetaAccessOverride(Company $company, bool $enabled): BillingProfile
    {
        $profile = $this->forCompany($company);
        $profile->beta_access_override = $enabled;
        $profile->save();

        return $profile;
    }
}
