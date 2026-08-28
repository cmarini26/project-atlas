<?php

namespace Tests\Feature\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\StripeWebhookProcessor;
use App\Billing\Testing\FakeBillingProvider;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeBillingProvider();
        $this->app->instance(BillingProvider::class, $this->fake);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    private function checkoutEvent(string $id = 'evt_checkout_1'): StripeWebhookEvent
    {
        return new StripeWebhookEvent($id, 'checkout.session.completed', [
            'client_reference_id' => $this->company->id,
            'customer' => 'cus_1',
            'subscription' => 'sub_1',
        ], [], false);
    }

    public function test_an_unverifiable_payload_is_rejected_with_400_and_recorded_nowhere(): void
    {
        $this->fake->rejectWebhookSignature();

        $this->postJson('/api/stripe/webhook', ['anything' => true])->assertStatus(400);

        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_a_verified_checkout_event_is_processed_and_recorded(): void
    {
        $this->fake
            ->nextWebhookEvent($this->checkoutEvent())
            ->seedSubscription(new SubscriptionSnapshot('sub_1', 'cus_1', 'active', 'price_1', null, false));

        $this->postJson('/api/stripe/webhook', [])
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_checkout_1',
            'type' => 'checkout.session.completed',
        ]);
        $this->assertNotNull(\App\Models\StripeWebhookEvent::first()->processed_at);
        $this->assertSame('active', $this->company->billingProfile()->first()->subscription_status);
    }

    public function test_a_duplicate_delivery_is_acknowledged_without_reprocessing(): void
    {
        $this->fake
            ->nextWebhookEvent($this->checkoutEvent('evt_dup'))
            ->seedSubscription(new SubscriptionSnapshot('sub_1', 'cus_1', 'active', null, null, false));

        $this->postJson('/api/stripe/webhook', [])->assertOk()->assertJson(['status' => 'processed']);

        $callsAfterFirst = count($this->fake->calls);
        $this->postJson('/api/stripe/webhook', [])->assertOk()->assertJson(['status' => 'duplicate']);

        // parseWebhookEvent runs again, but the processor (and its fetchSubscription) does not.
        $this->assertSame($callsAfterFirst + 1, count($this->fake->calls));
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }

    public function test_a_processing_failure_returns_500_and_records_the_error(): void
    {
        $this->fake->nextWebhookEvent($this->checkoutEvent('evt_boom'));

        $this->mock(StripeWebhookProcessor::class)
            ->shouldReceive('process')
            ->once()
            ->andThrow(new RuntimeException('kaboom'));

        $this->postJson('/api/stripe/webhook', [])->assertStatus(500);

        $record = \App\Models\StripeWebhookEvent::firstWhere('stripe_event_id', 'evt_boom');
        $this->assertNull($record->processed_at);
        $this->assertStringContainsString('kaboom', (string) $record->error);
    }
}
