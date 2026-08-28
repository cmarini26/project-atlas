<?php

namespace App\Billing;

use App\Models\Company;

/**
 * The single decision point for "may this company use paid Atlas features?".
 *
 * Beta-safe by default: with `billing.gate_enabled` off, everything is
 * allowed. With it on, access requires an entitling subscription status OR a
 * `beta_access_override` on the company's BillingProfile — the manual escape
 * hatch so a billing edge case never blocks early operations.
 */
class BillingAccess
{
    public function __construct(private readonly BillingProfileService $profiles) {}

    public function gatingEnabled(): bool
    {
        return (bool) config('billing.gate_enabled', false);
    }

    public function allows(Company $company): bool
    {
        if (! $this->gatingEnabled()) {
            return true;
        }

        return $this->profiles->find($company)?->grantsAccess() ?? false;
    }

    /** A short, user-facing reason when access is denied; null when allowed. */
    public function deniedReason(Company $company): ?string
    {
        if ($this->allows($company)) {
            return null;
        }

        return 'An active Atlas subscription is required to do this. Start one from Settings → Billing.';
    }
}
