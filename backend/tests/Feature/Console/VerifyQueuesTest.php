<?php

namespace Tests\Feature\Console;

use App\Jobs\QueueHealthProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerifyQueuesTest extends TestCase
{
    public function test_it_verifies_every_queue_with_the_sync_driver(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('atlas:verify-queues', ['--timeout' => 1])
            ->expectsOutput('All Atlas queues acknowledged the probe: high, ai, default, observations, maintenance')
            ->assertSuccessful();
    }

    public function test_it_fails_when_workers_do_not_consume_the_probes(): void
    {
        Queue::fake();

        $this->artisan('atlas:verify-queues', ['--timeout' => 1])
            ->expectsOutput('Queue verification timed out. Missing: high, ai, default, observations, maintenance')
            ->assertFailed();

        Queue::assertPushed(QueueHealthProbe::class, 5);
    }

    public function test_probe_acknowledges_its_queue(): void
    {
        $job = new QueueHealthProbe('probe-id', 'ai');

        $job->handle();

        $this->assertTrue(Cache::has(QueueHealthProbe::cacheKey('probe-id', 'ai')));
    }

    public function test_it_rejects_an_invalid_timeout(): void
    {
        $this->artisan('atlas:verify-queues', ['--timeout' => 0])
            ->expectsOutput('The --timeout value must be an integer between 1 and 300 seconds.')
            ->assertExitCode(2);
    }
}
