<?php

namespace App\Billing\Data;

use App\Billing\Contracts\BillingProvider;
use InvalidArgumentException;

/**
 * Everything needed to open a Stripe subscription Checkout Session. Built by
 * Atlas product code; the {@see BillingProvider} turns
 * it into a provider call.
 */
readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $customerId,
        public string $priceId,
        public string $successUrl,
        public string $cancelUrl,
        /** Company id — echoed back on the session so webhooks can map to a company. */
        public string $clientReferenceId,
        public array $metadata = [],
    ) {
        foreach ([
            'customerId' => $customerId,
            'priceId' => $priceId,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'clientReferenceId' => $clientReferenceId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("CheckoutRequest.{$field} must not be empty.");
            }
        }
    }
}
