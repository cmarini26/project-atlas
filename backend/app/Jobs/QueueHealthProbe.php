<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * A side-effect-free operational probe used to verify that each Atlas queue
 * is being consumed. The acknowledgement expires automatically.
 */
class QueueHealthProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $probeId,
        public readonly string $queueName,
    ) {
        $this->onQueue($queueName);
    }

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->probeId, $this->queueName), now()->toIso8601String(), now()->addMinutes(5));
    }

    public static function cacheKey(string $probeId, string $queueName): string
    {
        return "atlas:queue-probe:{$probeId}:{$queueName}";
    }
}
