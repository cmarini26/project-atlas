<?php

namespace App\Jobs;

use App\Models\ChannelCredentials;
use App\Services\Publishing\ChannelPublisherRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckChannelHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ChannelPublisherRegistry $registry): void
    {
        ChannelCredentials::withoutGlobalScopes()
            ->where('status', '!=', 'revoked')
            ->each(function (ChannelCredentials $credentials) use ($registry): void {
                try {
                    $publisher = $registry->for($credentials->channel_type);
                    $result = $publisher->ping($credentials);

                    $status = $result->reachable ? 'active' : 'error';

                    $credentials->update([
                        'status' => $status,
                        'last_used_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    $credentials->update(['status' => 'error']);

                    Log::error('CheckChannelHealth: ping failed.', [
                        'credentials_id' => $credentials->id,
                        'channel_type' => $credentials->channel_type,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
