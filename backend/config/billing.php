<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing Driver
    |--------------------------------------------------------------------------
    |
    | Which BillingProvider Atlas resolves. Explicit — never inferred from
    | credential presence. Supported: "stripe", "fake" (local/testing only).
    | Stripe credentials live in config/services.php under 'stripe'.
    |
    */

    'driver' => env('BILLING_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Subscription Plan
    |--------------------------------------------------------------------------
    |
    | The single Stripe Price the Atlas subscription checkout uses. During the
    | private beta there is one plan; this is a Price id (price_…), created in
    | the Stripe dashboard.
    |
    */

    'price_id' => env('STRIPE_PRICE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Access Gating
    |--------------------------------------------------------------------------
    |
    | When false (the beta-safe default), billing state never restricts
    | anything — every company can use every feature regardless of
    | subscription status. Turn it on only once billing is trusted end to
    | end. Even then, a company with billing_profiles.beta_access_override
    | set is always allowed. See CM-78 and the billing runbook.
    |
    */

    'gate_enabled' => (bool) env('BILLING_GATE_ENABLED', false),

];
