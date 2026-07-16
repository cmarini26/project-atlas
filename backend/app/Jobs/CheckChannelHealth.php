<?php

namespace App\Jobs;

use App\Models\ChannelCredentials;
use App\Models\CompanyMembership;
use App\Models\MarketingChannel;
use App\Notifications\ChannelNeedsReauth;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Publishing\ChannelPublisherRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckChannelHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ChannelPublisherRegistry $registry, MarketingPresenceService $marketingPresence): void
    {
        ChannelCredentials::withoutGlobalScopes()
            ->where('status', '!=', 'revoked')
            ->each(function (ChannelCredentials $credentials) use ($registry, $marketingPresence): void {
                $wasAlreadyError = $credentials->status === 'error';

                try {
                    $publisher = $registry->for($credentials->channel_type);
                    $result = $publisher->ping($credentials);

                    $status = $result->reachable ? 'active' : 'error';

                    $credentials->update([
                        'status' => $status,
                        'last_used_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    $status = 'error';

                    $credentials->update(['status' => 'error']);

                    Log::error('CheckChannelHealth: ping failed.', [
                        'credentials_id' => $credentials->id,
                        'channel_type' => $credentials->channel_type,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Keep the declared channel's capability badge honest as
                // health changes over time — supports_publishing shouldn't
                // stay true forever just because a connection once passed
                // its initial verification (see channelCapability.ts).
                $declared = MarketingChannel::where('company_id', $credentials->company_id)
                    ->where('type', $credentials->channel_type)
                    ->whereNotNull('channel_id')
                    ->first();

                if ($declared !== null) {
                    $marketingPresence->markPublishingVerified($declared, $status === 'active');
                }

                // Notify once on the active→error transition, not on every
                // 30-minute tick a still-broken credential keeps failing.
                if ($status === 'error' && ! $wasAlreadyError) {
                    $this->notifyOwner($credentials);
                }
            });
    }

    private function notifyOwner(ChannelCredentials $credentials): void
    {
        $owner = CompanyMembership::withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $credentials->company_id)
            ->where('role', 'owner')
            ->first();

        if ($owner === null || $owner->user === null) {
            return;
        }

        $owner->user->notify(new ChannelNeedsReauth($credentials));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CheckChannelHealth: job failed after exhausting retries.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
