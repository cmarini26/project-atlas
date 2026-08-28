<?php

namespace App\Billing\Data;

/**
 * A verified Stripe webhook event, provider-agnostic in shape. `object` is the
 * `data.object` payload (the resource the event is about); `raw` is the whole
 * event should a handler need something else.
 */
readonly class StripeWebhookEvent
{
    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $object,
        public array $raw,
        public bool $livemode,
    ) {}

    /** @param array<string, mixed> $stripeEvent a decoded, signature-verified Stripe event */
    public static function fromStripeArray(array $stripeEvent): self
    {
        $object = $stripeEvent['data']['object'] ?? [];

        return new self(
            id: (string) ($stripeEvent['id'] ?? ''),
            type: (string) ($stripeEvent['type'] ?? ''),
            object: is_array($object) ? $object : [],
            raw: $stripeEvent,
            livemode: (bool) ($stripeEvent['livemode'] ?? false),
        );
    }
}
