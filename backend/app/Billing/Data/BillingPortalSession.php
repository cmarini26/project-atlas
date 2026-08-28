<?php

namespace App\Billing\Data;

/**
 * A Stripe-hosted billing management portal session. `url` is single-use and
 * short-lived — generate one per click, never store it.
 */
readonly class BillingPortalSession
{
    public function __construct(
        public string $url,
    ) {}
}
