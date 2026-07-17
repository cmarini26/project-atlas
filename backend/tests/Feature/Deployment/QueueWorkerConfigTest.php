<?php

namespace Tests\Feature\Deployment;

use App\Jobs\Testing\QueueRoutingProbeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SCRUM-41. `queue:work`'s first positional argument is a CONNECTION name,
 * not a queue name — a real, previously-shipped bug in
 * infrastructure/supervisor/atlas-worker.conf passed the queue name
 * (`queue:work high`) as that positional argument. Every job in this app
 * dispatches via $this->onQueue(...) only (no job ever calls onConnection()),
 * so every job is pushed onto whatever QUEUE_CONNECTION resolves to (the
 * "database" connection — see .env.example) — never the same-named
 * `high`/`ai`/`observations`/`maintenance` Redis-driver connections
 * config/queue.php separately defines. `queue:work high` therefore listens
 * on an empty Redis list and would never process a single job dispatched
 * onto the database connection's `queue = 'high'` rows. Confirmed by a real
 * dispatch-and-consume test below, not just static analysis of the config
 * file — see docs/deployment/Queue-Workers.md for the full writeup.
 */
class QueueWorkerConfigTest extends TestCase
{
    use RefreshDatabase;

    private function supervisorConfContents(): string
    {
        return (string) file_get_contents(
            base_path('../infrastructure/supervisor/atlas-worker.conf')
        );
    }

    public function test_supervisor_conf_never_passes_a_bare_connection_argument_to_queue_work(): void
    {
        $contents = $this->supervisorConfContents();
        preg_match_all('/^command=(.*)$/m', $contents, $matches);

        $this->assertNotEmpty($matches[1], 'Expected at least one command= line in atlas-worker.conf.');

        foreach ($matches[1] as $command) {
            // The dangerous form is `queue:work high` (or ai/observations/
            // maintenance/default) — a bare word immediately after
            // "queue:work" with no leading "--". The correct form always
            // filters by queue name via --queue=, with no positional
            // connection argument, so the worker uses the app's actual
            // default connection.
            $this->assertMatchesRegularExpression(
                '/queue:work\s+--queue=/',
                $command,
                "Every queue:work invocation must use --queue=<name> with no connection argument: {$command}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/queue:work\s+(high|ai|default|observations|maintenance)\b/',
                $command,
                "queue:work must never receive a queue name as a bare (connection) argument: {$command}",
            );
        }
    }

    public function test_a_job_dispatched_via_onqueue_is_only_reachable_by_the_corrected_worker_form(): void
    {
        // phpunit.xml forces QUEUE_CONNECTION=sync for the rest of the
        // suite (jobs run inline, no queue table involved) — override just
        // this test to the real .env.example production default so the
        // dispatch below behaves exactly as it would in production.
        config(['queue.default' => 'database']);

        // Real dispatch, matching exactly how every real job in app/Jobs
        // routes itself: onQueue() only, no onConnection() call, so it
        // lands on config('queue.default') (the "database" connection
        // locally and in .env.example) with its queue column set.
        QueueRoutingProbeJob::dispatch();

        $this->assertSame('database', config('queue.default'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('high', DB::table('jobs')->value('queue'));

        // The corrected worker form (matching the fixed Supervisor conf):
        // no connection argument, --queue= filters within the default
        // connection — this must actually consume the job.
        Log::shouldReceive('info')->once()->with('QueueRoutingProbeJob ran');
        Artisan::call('queue:work', ['--queue' => 'high', '--once' => true, '--stop-when-empty' => true]);

        $this->assertSame(0, DB::table('jobs')->count(), 'The corrected --queue= form must consume the job.');
    }
}
