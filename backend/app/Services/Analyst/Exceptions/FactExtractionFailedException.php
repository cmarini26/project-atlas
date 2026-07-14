<?php

namespace App\Services\Analyst\Exceptions;

use RuntimeException;

/**
 * Thrown when the AI provider responded but its output could not be turned
 * into facts — invalid JSON, a payload without a "facts" array, or an empty
 * facts list for a page that had analyzable content.
 *
 * ProcessObservation treats this like any other failure: the observation is
 * marked "failed" — surfaced in the Discovery progress screen as that
 * asset's connector attempt staying failed, not as a whole-run failure.
 */
class FactExtractionFailedException extends RuntimeException {}
