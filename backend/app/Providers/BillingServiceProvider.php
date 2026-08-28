<?php

namespace App\Providers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Providers\StripeBillingProvider;
use App\Billing\Testing\FakeBillingProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Explicit driver selection, mirroring the AI provider bindings. The
        // fake is confined to non-production so tests and local dev never hit
        // Stripe. Supported: stripe, fake.
        $this->app->singleton(BillingProvider::class, function ($app): BillingProvider {
            $driver = config('billing.driver');

            if (! is_string($driver) || trim($driver) === '') {
                throw new InvalidArgumentException('BILLING_DRIVER must be configured. Supported values: stripe, fake.');
            }

            return match ($driver) {
                'stripe' => $app->make(StripeBillingProvider::class),
                'fake' => $app->environment('local', 'testing')
                    ? $app->make(FakeBillingProvider::class)
                    : throw new InvalidArgumentException('BILLING_DRIVER=fake is only supported in local and testing environments.'),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported BILLING_DRIVER value [%s]. Supported values: stripe, fake.',
                    $driver,
                )),
            };
        });

        // Keep the fake a shared instance so tests that resolve it and code
        // under test see the same recorded calls.
        $this->app->singleton(FakeBillingProvider::class);
    }
}
