<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ProductionDeployScriptTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = dirname(__DIR__, 4).'/infrastructure/deploy/deploy-production.sh';
    }

    public function test_production_deploy_script_has_valid_shell_syntax(): void
    {
        $result = Process::run(['bash', '-n', $this->script]);

        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    public function test_deploy_preserves_uploads_and_creates_public_storage_link(): void
    {
        $contents = file_get_contents($this->script);

        $this->assertIsString($contents);
        $this->assertStringContainsString("--exclude='backend/storage/'", $contents);
        $this->assertStringContainsString("--exclude='backend/public/storage'", $contents);
        $this->assertStringContainsString('if [ ! -e "${BACKEND_ROOT}/public/storage" ] && [ ! -L "${BACKEND_ROOT}/public/storage" ]; then', $contents);
        $this->assertStringContainsString('php artisan storage:link', $contents);
        $this->assertStringContainsString('test -L "${BACKEND_ROOT}/public/storage"', $contents);
        $this->assertStringContainsString(
            'test "$(readlink -f "${BACKEND_ROOT}/public/storage")" = "$(readlink -f "${BACKEND_ROOT}/storage/app/public")"',
            $contents,
        );
        $this->assertLessThan(
            strpos($contents, 'php artisan optimize'),
            strpos($contents, 'php artisan storage:link'),
            'The storage link must be verified before application caches are optimized.',
        );
    }

    public function test_deploy_checks_supervisor_worker_groups(): void
    {
        $contents = file_get_contents($this->script);

        $this->assertIsString($contents);
        $this->assertStringContainsString(
            'worker_status="$(supervisorctl status "atlas-worker-${worker}:*" 2>&1 || true)"',
            $contents,
        );
        $this->assertStringContainsString(
            'for attempt in {1..10}; do',
            $contents,
        );
        $this->assertStringContainsString(
            "! grep -qv ' RUNNING ' <<< \"\$worker_status\"",
            $contents,
        );
        $this->assertStringContainsString('sleep 3', $contents);
        $this->assertStringContainsString('did not become healthy', $contents);
    }
}
