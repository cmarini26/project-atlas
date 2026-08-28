<?php

namespace Tests\Unit\Billing;

use App\Billing\Data\SubscriptionSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionSnapshotTest extends TestCase
{
    public function test_it_maps_a_stripe_subscription_array(): void
    {
        $snapshot = SubscriptionSnapshot::fromStripeArray([
            'id' => 'sub_123',
            'customer' => 'cus_123',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => 1_800_000_000,
            'items' => ['data' => [['price' => ['id' => 'price_abc']]]],
        ]);

        $this->assertSame('sub_123', $snapshot->id);
        $this->assertSame('cus_123', $snapshot->customerId);
        $this->assertSame('active', $snapshot->status);
        $this->assertSame('price_abc', $snapshot->priceId);
        $this->assertTrue($snapshot->cancelAtPeriodEnd);
        $this->assertSame(1_800_000_000, $snapshot->currentPeriodEnd?->getTimestamp());
    }

    public function test_it_tolerates_a_sparse_array(): void
    {
        $snapshot = SubscriptionSnapshot::fromStripeArray(['id' => 'sub_1', 'customer' => 'cus_1']);

        $this->assertSame('incomplete', $snapshot->status);
        $this->assertNull($snapshot->priceId);
        $this->assertNull($snapshot->currentPeriodEnd);
        $this->assertFalse($snapshot->cancelAtPeriodEnd);
    }

    #[DataProvider('accessStatuses')]
    public function test_grants_access_only_for_paying_statuses(string $status, bool $expected): void
    {
        $snapshot = new SubscriptionSnapshot('sub', 'cus', $status, null, null, false);

        $this->assertSame($expected, $snapshot->grantsAccess());
    }

    /** @return array<string, array{string, bool}> */
    public static function accessStatuses(): array
    {
        return [
            'trialing' => ['trialing', true],
            'active' => ['active', true],
            'past_due' => ['past_due', true],
            'canceled' => ['canceled', false],
            'unpaid' => ['unpaid', false],
            'incomplete' => ['incomplete', false],
            'paused' => ['paused', false],
        ];
    }
}
