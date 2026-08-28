<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

/**
 * The state of a Stripe subscription at a point in time, normalised to the
 * fields Atlas cares about for access gating and support visibility.
 */
readonly class SubscriptionSnapshot
{
    /**
     * @param  string  $status  Stripe subscription status: trialing, active,
     *                          past_due, canceled, incomplete, incomplete_expired,
     *                          unpaid, paused
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public ?string $priceId,
        public ?CarbonImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd,
    ) {}

    /** Whether this subscription currently entitles the customer to paid access. */
    public function grantsAccess(): bool
    {
        return in_array($this->status, ['trialing', 'active', 'past_due'], true);
    }

    /**
     * @param  array<string, mixed>  $data  a Stripe subscription object as an assoc array
     */
    public static function fromStripeArray(array $data): self
    {
        $periodEnd = $data['current_period_end'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            customerId: (string) ($data['customer'] ?? ''),
            status: (string) ($data['status'] ?? 'incomplete'),
            priceId: self::firstPriceId($data),
            currentPeriodEnd: is_int($periodEnd) ? CarbonImmutable::createFromTimestampUTC($periodEnd) : null,
            cancelAtPeriodEnd: (bool) ($data['cancel_at_period_end'] ?? false),
        );
    }

    /** @param array<string, mixed> $data */
    private static function firstPriceId(array $data): ?string
    {
        $items = $data['items']['data'] ?? [];
        $price = is_array($items) ? ($items[0]['price']['id'] ?? null) : null;

        return is_string($price) ? $price : null;
    }
}
