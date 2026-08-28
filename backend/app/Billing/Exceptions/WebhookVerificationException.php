<?php

namespace App\Billing\Exceptions;

/**
 * A webhook payload failed signature verification, or its body was malformed.
 * The webhook endpoint responds 400 on this — never 500 — so Stripe stops
 * retrying a request it can never satisfy.
 */
class WebhookVerificationException extends BillingException {}
