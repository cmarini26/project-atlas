<?php

namespace App\Services\Observatory\Connectors\Instagram\Exceptions;

use RuntimeException;

/**
 * Thrown when the Instagram Graph API request fails outright, or succeeds
 * but returns a response missing the fields a profile snapshot requires.
 */
class InstagramApiException extends RuntimeException {}
