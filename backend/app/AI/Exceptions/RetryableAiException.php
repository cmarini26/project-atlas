<?php

namespace App\AI\Exceptions;

use Throwable;

/**
 * Marks an AI provider failure as transient and safe to retry. Queue jobs and
 * the onboarding status endpoint treat these as "retrying", not "failed", so a
 * temporary provider blip never permanently fails an observation.
 *
 * Implemented by both the hosted-provider overload signal
 * ({@see AiProviderOverloadedException}) and the local-provider unavailable
 * signal ({@see LocalAiUnavailableException}).
 */
interface RetryableAiException extends Throwable {}
