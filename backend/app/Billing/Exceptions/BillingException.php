<?php

namespace App\Billing\Exceptions;

use RuntimeException;

/**
 * A billing-provider failure. Vendor SDK exceptions are wrapped in this type so
 * product code never catches a `Stripe\Exception\*`.
 */
class BillingException extends RuntimeException {}
