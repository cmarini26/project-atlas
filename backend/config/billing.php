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

];
