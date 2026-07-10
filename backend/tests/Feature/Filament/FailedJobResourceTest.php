<?php

namespace Tests\Feature\Filament;

use App\Jobs\PruneRawMetrics;
use App\Models\FailedJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 5's failed-job visibility requirement
 * — see docs/plans/Critical-Production-Blockers.md. Access to the resource
 * is gated by the same panel-level superadmin check every other Filament
 * resource in this app already relies on (see
 * tests/Feature/Auth/FilamentAuthorizationTest.php); this file only proves
 * the new resource itself renders the diagnostics operators need.
 */
class FailedJobResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFailedJob(): FailedJob
    {
        $payload = [
            'uuid' => (string) Str::uuid(),
            'displayName' => PruneRawMetrics::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'attempts' => 3,
        ];

        return FailedJob::query()->create([
            'uuid' => $payload['uuid'],
            'connection' => 'database',
            'queue' => 'maintenance',
            'payload' => json_encode($payload),
            'exception' => "Exception: Something went wrong in /app/Jobs/PruneRawMetrics.php:29\nStack trace:\n#0 {main}",
        ]);
    }

    // ── Visibility ────────────────────────────────────────────────────────────

    public function test_superadmin_can_view_the_failed_jobs_list(): void
    {
        $admin = User::factory()->superadmin()->create();
        $failedJob = $this->makeFailedJob();

        $response = $this->actingAs($admin)->get('/admin/failed-jobs');

        $response->assertSuccessful();
        $response->assertSee('maintenance');
        $response->assertSee($failedJob->jobClass());
    }

    public function test_superadmin_can_view_a_failed_job_detail_page(): void
    {
        $admin = User::factory()->superadmin()->create();
        $failedJob = $this->makeFailedJob();

        $response = $this->actingAs($admin)->get("/admin/failed-jobs/{$failedJob->id}");

        $response->assertSuccessful();
        $response->assertSee($failedJob->uuid);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_regular_user_cannot_view_the_failed_jobs_list(): void
    {
        $user = User::factory()->create();
        $this->makeFailedJob();

        $response = $this->actingAs($user)->get('/admin/failed-jobs');

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->makeFailedJob();

        $response = $this->get('/admin/failed-jobs');

        $response->assertRedirect('/admin/login');
    }

    // ── Regression: unrelated resources still work ───────────────────────────

    public function test_the_failed_jobs_resource_has_no_create_page(): void
    {
        $admin = User::factory()->superadmin()->create();

        $response = $this->actingAs($admin)->get('/admin/failed-jobs/create');

        $response->assertNotFound();
    }

    public function test_the_admin_panel_root_still_loads_for_superadmin(): void
    {
        $admin = User::factory()->superadmin()->create();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertSuccessful();
    }
}
