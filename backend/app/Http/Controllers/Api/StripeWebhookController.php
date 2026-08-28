<?php

namespace App\Http\Controllers\Api;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Exceptions\WebhookVerificationException;
use App\Billing\StripeWebhookProcessor;
use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Stripe webhooks. Unauthenticated by design — the `Stripe-Signature`
 * header verified against the endpoint secret is the gate. Atlas updates
 * billing state from these events rather than trusting the client redirect.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly BillingProvider $billing,
        private readonly StripeWebhookProcessor $processor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $event = $this->billing->parseWebhookEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
            );
        } catch (WebhookVerificationException $e) {
            Log::warning('StripeWebhookController: rejected an unverifiable webhook.', [
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            // 400 so Stripe stops retrying a request it can never satisfy.
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $record = StripeWebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['type' => $event->type, 'received_at' => now()],
        );

        if (! $record->wasRecentlyCreated && $record->isProcessed()) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $this->processor->process($event);
        } catch (Throwable $e) {
            $record->forceFill(['error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            Log::error('StripeWebhookController: processing failed.', [
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'exception' => $e::class,
            ]);

            // 500 so Stripe retries — the processor is idempotent.
            return response()->json(['error' => 'Processing failed.'], 500);
        }

        $record->forceFill(['processed_at' => now(), 'error' => null])->save();

        return response()->json(['status' => 'processed']);
    }
}
