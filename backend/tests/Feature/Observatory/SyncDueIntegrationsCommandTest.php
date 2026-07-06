<?php

namespace Tests\Feature\Observatory;

use App\Jobs\ExpireOpportunities;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\Integration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncDueIntegrationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Loop Co',
            'slug' => 'loop-co',
        ]);
    }

    public function test_dispatches_sync_for_due_active_integrations(): void
    {
        Bus::fake();

        $due = $this->makeIntegration(['status' => 'active', 'next_run_at' => now()->subMinutes(5)]);

        $this->artisan('atlas:sync-due-integrations')->assertSuccessful();

        Bus::assertDispatched(SyncIntegration::class, function (SyncIntegration $job) use ($due): bool {
            return $job->integration->id === $due->id;
        });
    }

    public function test_skips_integrations_not_yet_due(): void
    {
        Bus::fake();

        $this->makeIntegration(['status' => 'active', 'next_run_at' => now()->addHours(3)]);

        $this->artisan('atlas:sync-due-integrations')->assertSuccessful();

        Bus::assertNotDispatched(SyncIntegration::class);
    }

    public function test_skips_errored_integrations(): void
    {
        Bus::fake();

        $this->makeIntegration(['status' => 'error', 'next_run_at' => now()->subDay()]);

        $this->artisan('atlas:sync-due-integrations')->assertSuccessful();

        Bus::assertNotDispatched(SyncIntegration::class);
    }

    public function test_skips_integrations_without_next_run_at(): void
    {
        Bus::fake();

        $this->makeIntegration(['status' => 'active', 'next_run_at' => null]);

        $this->artisan('atlas:sync-due-integrations')->assertSuccessful();

        Bus::assertNotDispatched(SyncIntegration::class);
    }

    public function test_recurring_loop_jobs_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue(
            $events->contains(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'atlas:sync-due-integrations')),
            'Expected atlas:sync-due-integrations to be scheduled.',
        );

        $this->assertTrue(
            $events->contains(fn ($event): bool => ($event->description ?? '') === ExpireOpportunities::class),
            'Expected ExpireOpportunities to be scheduled.',
        );
    }

    /** @param array<string, mixed> $attributes */
    private function makeIntegration(array $attributes): Integration
    {
        return Integration::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://loop-co.example.com'],
            'status' => 'active',
        ], $attributes));
    }
}
