<?php

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueDispatchTest extends TestCase
{
    public function test_jobs_can_be_dispatched_to_queues(): void
    {
        Queue::fake();

        $job = new class() implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public function handle(): void {}
        };

        dispatch($job->onQueue('default'));

        Queue::assertPushed($job::class);
    }

    public function test_jobs_can_be_dispatched_to_named_atlas_queues(): void
    {
        Queue::fake();

        $queues = ['high', 'ai', 'default', 'observations', 'maintenance'];

        foreach ($queues as $queueName) {
            $job = new class() implements ShouldQueue
            {
                use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

                public function handle(): void {}
            };

            dispatch($job->onQueue($queueName));
        }

        Queue::assertCount(count($queues));
    }

    public function test_queue_configuration_includes_all_atlas_queues(): void
    {
        $connections = config('queue.connections');

        $this->assertArrayHasKey('high', $connections);
        $this->assertArrayHasKey('ai', $connections);
        $this->assertArrayHasKey('observations', $connections);
        $this->assertArrayHasKey('maintenance', $connections);
        $this->assertArrayHasKey('redis', $connections);
    }
}
