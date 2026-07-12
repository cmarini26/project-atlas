<?php

namespace App\Jobs;

use App\Models\ChannelCredentials;
use App\Models\CompanyMembership;
use App\Notifications\ChannelNeedsReauth;
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

    public function handle(ChannelPublisherRegistry $registry): void
    {
        ChannelCredentials::withoutGlobalScopes()
            ->where('status', '!=', 'revoked')
            ->each(function (ChannelCredentials $credentials) use ($registry): void {
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
