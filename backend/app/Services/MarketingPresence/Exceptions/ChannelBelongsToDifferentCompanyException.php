<?php

namespace App\Services\MarketingPresence\Exceptions;

use RuntimeException;

/**
 * Thrown by MarketingPresenceService::link() when the Channel being linked
 * does not belong to the same company as the MarketingChannel — including
 * global/system Channel templates (company_id === null). Linking across a
 * tenant boundary is never valid, regardless of who calls it.
 */
class ChannelBelongsToDifferentCompanyException extends RuntimeException {}
