<?php

namespace App\Events;

use App\Models\MarketingChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after any create, update, status change, or link of a
 * MarketingChannel. One coarse event, not one per verb — the only consumer
 * (Business Brain cache invalidation, Phase 5) doesn't care what changed,
 * only that it changed. See specs/core/marketing-presence.md §8.
 *
 * No listener is registered for this event yet. Wiring it to
 * BusinessBrainService::invalidate() is explicitly Phase 5 work — this
 * class exists now, inert, so Phase 2's service has something to dispatch.
 */
class MarketingPresenceUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly MarketingChannel $marketingChannel) {}
}
