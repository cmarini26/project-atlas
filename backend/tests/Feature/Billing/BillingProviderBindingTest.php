<?php

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Providers\StripeBillingProvider;
use App\Billing\Testing\FakeBillingProvider;
use InvalidArgumentException;
use Tests\TestCase;

class BillingProviderBindingTest extends TestCase
{
    private function resolve(): BillingProvider
    {
        $this->app->forgetInstance(BillingProvider::class);

        return $this->app->make(BillingProvider::class);
    }

    public function test_fake_driver_resolves_in_testing(): void
    {
        config()->set('billing.driver', 'fake');

        $this->assertInstanceOf(FakeBillingProvider::class, $this->resolve());
    }

    public function test_stripe_driver_resolves_when_configured(): void
    {
        config()->set('billing.driver', 'stripe');
        config()->set('services.stripe.secret', 'sk_test_binding');

        $this->assertInstanceOf(StripeBillingProvider::class, $this->resolve());
    }

    public function test_blank_driver_throws(): void
    {
        config()->set('billing.driver', null);

        $this->expectException(InvalidArgumentException::class);

        $this->resolve();
    }

    public function test_unknown_driver_throws(): void
    {
        config()->set('billing.driver', 'paypal');

        $this->expectException(InvalidArgumentException::class);

        $this->resolve();
    }
}
