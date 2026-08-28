<?php

namespace App\Billing\Testing;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\BillingPortalSession;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSession;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\Exceptions\WebhookVerificationException;
use PHPUnit\Framework\Assert;

/**
 * Deterministic in-memory {@see BillingProvider} for tests and local dev.
 * Never touches the network. Records every call; lets a test seed the next
 * webhook event and subscription snapshots.
 */
class FakeBillingProvider implements BillingProvider
{
    /** @var array<string, string> companyId => customerId */
    private array $customers = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, SubscriptionSnapshot> subscriptionId => snapshot */
    private array $subscriptions = [];

    private ?StripeWebhookEvent $nextWebhookEvent = null;

    private bool $webhookSignatureValid = true;

    public string $checkoutUrl = 'https://checkout.stripe.test/session/cs_test_fake';

    public string $portalUrl = 'https://billing.stripe.test/portal/fake';

    public function ensureCustomer(string $companyId, string $email, string $name): string
    {
        $this->calls[] = ['method' => 'ensureCustomer', 'args' => compact('companyId', 'email', 'name')];

        return $this->customers[$companyId] ??= 'cus_fake_'.substr(md5($companyId), 0, 14);
    }

    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->calls[] = ['method' => 'createSubscriptionCheckout', 'args' => ['request' => $request]];

        return new CheckoutSession('cs_test_fake_'.substr(md5($request->clientReferenceId), 0, 12), $this->checkoutUrl);
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): BillingPortalSession
    {
        $this->calls[] = ['method' => 'createBillingPortalSession', 'args' => compact('customerId', 'returnUrl')];

        return new BillingPortalSession($this->portalUrl);
    }

    public function parseWebhookEvent(string $payload, string $signatureHeader): StripeWebhookEvent
    {
        $this->calls[] = ['method' => 'parseWebhookEvent', 'args' => compact('payload', 'signatureHeader')];

        if (! $this->webhookSignatureValid) {
            throw new WebhookVerificationException('Fake: signature rejected.');
        }

        if ($this->nextWebhookEvent !== null) {
            return $this->nextWebhookEvent;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new WebhookVerificationException('Fake: payload was not valid JSON.');
        }

        return StripeWebhookEvent::fromStripeArray($decoded);
    }

    public function fetchSubscription(string $subscriptionId): SubscriptionSnapshot
    {
        $this->calls[] = ['method' => 'fetchSubscription', 'args' => compact('subscriptionId')];

        return $this->subscriptions[$subscriptionId]
            ?? new SubscriptionSnapshot($subscriptionId, 'cus_fake', 'active', 'price_fake', null, false);
    }

    // ---- test controls -------------------------------------------------------

    public function assumeCustomer(string $companyId, string $customerId): static
    {
        $this->customers[$companyId] = $customerId;

        return $this;
    }

    public function seedSubscription(SubscriptionSnapshot $snapshot): static
    {
        $this->subscriptions[$snapshot->id] = $snapshot;

        return $this;
    }

    public function nextWebhookEvent(StripeWebhookEvent $event): static
    {
        $this->nextWebhookEvent = $event;

        return $this;
    }

    public function rejectWebhookSignature(): static
    {
        $this->webhookSignatureValid = false;

        return $this;
    }

    public function assertCalled(string $method): void
    {
        Assert::assertContains(
            $method,
            array_column($this->calls, 'method'),
            "Expected BillingProvider::{$method}() to have been called.",
        );
    }

    public function assertNotCalled(string $method): void
    {
        Assert::assertNotContains(
            $method,
            array_column($this->calls, 'method'),
            "Expected BillingProvider::{$method}() NOT to have been called.",
        );
    }
}
