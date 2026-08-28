<?php

namespace App\Billing\Data;

/**
 * A created Stripe Checkout Session. `url` is the Stripe-hosted page the
 * frontend must redirect the browser to.
 */
readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}
}
