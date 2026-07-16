<?php

namespace App\Services\Publishing\Email\Exceptions;

use RuntimeException;

/**
 * Thrown by EmailAudienceService::addMember() when the EmailContact being
 * added does not belong to the same company as the EmailAudience — the same
 * enforcement style MarketingPresenceService::link() already uses for
 * Channel/MarketingChannel. Crossing a tenant boundary is never valid,
 * regardless of who calls it.
 */
class ContactBelongsToDifferentCompanyException extends RuntimeException {}
