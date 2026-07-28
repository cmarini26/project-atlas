<?php

namespace App\Console\Commands;

use App\Jobs\QueueHealthProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VerifyQueues extends Command
{
    private const QUEUES = ['high', 'ai', 'default', 'observations', 'maintenance'];

    protected $signature = 'atlas:verify-queues {--timeout=30 : Seconds to wait for every queue acknowledgement}';

    protected $description = 'Dispatch harmless probes and verify that every Atlas queue is being consumed';

    public function handle(): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300],
        ]);

        if ($timeout === false) {
            $this->error('The --timeout value must be an integer between 1 and 300 seconds.');

            return self::INVALID;
        }

        $probeId = (string) Str::uuid();

        foreach (self::QUEUES as $queueName) {
            QueueHealthProbe::dispatch($probeId, $queueName);
        }

        $deadline = microtime(true) + $timeout;
        $pending = self::QUEUES;

        do {
            $pending = array_values(array_filter(
                self::QUEUES,
                fn (string $queueName): bool => ! Cache::has(QueueHealthProbe::cacheKey($probeId, $queueName)),
            ));

            if ($pending === []) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        foreach (self::QUEUES as $queueName) {
            Cache::forget(QueueHealthProbe::cacheKey($probeId, $queueName));
        }

        if ($pending !== []) {
            $this->error('Queue verification timed out. Missing: '.implode(', ', $pending));

            return self::FAILURE;
        }

        $this->info('All Atlas queues acknowledged the probe: '.implode(', ', self::QUEUES));

        return self::SUCCESS;
    }
}
