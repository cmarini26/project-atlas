<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Data\BillingPortalSession;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSession;
use App\Billing\Data\StripeWebhookEvent;
use App\Billing\Data\SubscriptionSnapshot;
use App\Billing\Exceptions\BillingException;
use App\Billing\Exceptions\WebhookVerificationException;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Stripe implementation of {@see BillingProvider}. The only class in the app
 * that touches the Stripe SDK. All Stripe SDK exceptions are wrapped in
 * {@see BillingException} so they never reach product code.
 *
 * Config (config/services.php → 'stripe'):
 *   - secret          STRIPE_SECRET (sk_test_… / sk_live_…)
 *   - webhook_secret  STRIPE_WEBHOOK_SECRET (whsec_…)
 */
final class StripeBillingProvider implements BillingProvider
{
    private StripeClient $client;

    private string $webhookSecret;

    public function __construct(?StripeClient $client = null, ?string $webhookSecret = null)
    {
        $secret = (string) config('services.stripe.secret', '');

        if ($client === null && trim($secret) === '') {
            throw new BillingException('STRIPE_SECRET is not configured.');
        }

        $this->client = $client ?? new StripeClient($secret);
        $this->webhookSecret = $webhookSecret ?? (string) config('services.stripe.webhook_secret', '');
    }

    public function ensureCustomer(string $companyId, string $email, string $name): string
    {
        return $this->guard('ensureCustomer', function () use ($companyId, $email, $name): string {
            // Idempotent per company: reuse an existing customer tagged with
            // this company id before creating a new one.
            $existing = $this->client->customers->search([
                'query' => sprintf("metadata['atlas_company_id']:'%s'", $companyId),
                'limit' => 1,
            ]);

            if (! empty($existing->data)) {
                return (string) $existing->data[0]->id;
            }

            $customer = $this->client->customers->create([
                'email' => $email,
                'name' => $name,
                'metadata' => ['atlas_company_id' => $companyId],
            ]);

            return (string) $customer->id;
        });
    }

    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->guard('createSubscriptionCheckout', function () use ($request): CheckoutSession {
            $session = $this->client->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $request->customerId,
                'line_items' => [['price' => $request->priceId, 'quantity' => 1]],
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'client_reference_id' => $request->clientReferenceId,
                'metadata' => $request->metadata,
                'subscription_data' => ['metadata' => $request->metadata],
            ]);

            return new CheckoutSession((string) $session->id, (string) $session->url);
        });
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): BillingPortalSession
    {
        return $this->guard('createBillingPortalSession', function () use ($customerId, $returnUrl): BillingPortalSession {
            $session = $this->client->billingPortal->sessions->create([
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]);

            return new BillingPortalSession((string) $session->url);
        });
    }

    public function parseWebhookEvent(string $payload, string $signatureHeader): StripeWebhookEvent
    {
        if (trim($this->webhookSecret) === '') {
            throw new WebhookVerificationException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
        } catch (SignatureVerificationException $e) {
            throw new WebhookVerificationException('Stripe webhook signature verification failed.', previous: $e);
        } catch (UnexpectedValueException $e) {
            throw new WebhookVerificationException('Stripe webhook payload was not valid JSON.', previous: $e);
        }

        return StripeWebhookEvent::fromStripeArray($event->toArray());
    }

    public function fetchSubscription(string $subscriptionId): SubscriptionSnapshot
    {
        return $this->guard('fetchSubscription', function () use ($subscriptionId): SubscriptionSnapshot {
            $subscription = $this->client->subscriptions->retrieve($subscriptionId);

            return SubscriptionSnapshot::fromStripeArray($subscription->toArray());
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            Log::error('StripeBillingProvider: Stripe API error.', [
                'operation' => $operation,
                'stripe_code' => $e->getStripeCode(),
                'http_status' => $e->getHttpStatus(),
            ]);

            throw new BillingException("Stripe {$operation} failed: {$e->getError()?->message}", previous: $e);
        } catch (Throwable $e) {
            if ($e instanceof BillingException) {
                throw $e;
            }

            Log::error('StripeBillingProvider: unexpected error.', [
                'operation' => $operation,
                'exception' => $e::class,
            ]);

            throw new BillingException("Stripe {$operation} failed unexpectedly.", previous: $e);
        }
    }
}
