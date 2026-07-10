<?php

namespace Tests\Feature\Backup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 8's safety requirements — see
 * docs/plans/Critical-Production-Blockers.md and
 * docs/operations/Backup-and-Recovery.md. These tests exercise argument
 * parsing and safety checks only (missing env vars, missing files,
 * mismatched restore confirmation) — none require a reachable Postgres
 * server or a specific pg_dump/psql version, unlike
 * BackupRestoreDrillTest, so they run reliably in any environment with a
 * shell.
 */
class BackupScriptSafetyTest extends TestCase
{
    private string $backupScript;

    private string $verifyScript;

    private string $restoreScript;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 4).'/infrastructure/backup';
        $this->backupScript = "{$root}/atlas-db-backup.sh";
        $this->verifyScript = "{$root}/atlas-db-verify.sh";
        $this->restoreScript = "{$root}/atlas-db-restore.sh";
    }

    /**
     * Every DB_* variable defaults to explicitly unset (Symfony Process
     * treats a null value as "remove this from the child's environment"),
     * so a test never accidentally inherits real credentials from this
     * machine's own .env — only what a test explicitly overrides is set.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    private function cleanEnv(array $overrides = []): array
    {
        return array_merge([
            'DB_HOST' => null,
            'DB_PORT' => null,
            'DB_DATABASE' => null,
            'DB_USERNAME' => null,
            'DB_PASSWORD' => null,
        ], $overrides);
    }

    private function combinedOutput(ProcessResult $result): string
    {
        return $result->output().$result->errorOutput();
    }

    // ── Scripts exist and are executable ─────────────────────────────────────

    public function test_the_backup_script_exists_and_is_executable(): void
    {
        $this->assertFileExists($this->backupScript);
        $this->assertTrue(is_executable($this->backupScript));
    }

    public function test_the_verify_script_exists_and_is_executable(): void
    {
        $this->assertFileExists($this->verifyScript);
        $this->assertTrue(is_executable($this->verifyScript));
    }

    public function test_the_restore_script_exists_and_is_executable(): void
    {
        $this->assertFileExists($this->restoreScript);
        $this->assertTrue(is_executable($this->restoreScript));
    }

    // ── Backup script fails loudly on missing configuration ──────────────────

    public function test_backup_fails_loudly_when_db_host_is_missing(): void
    {
        $result = Process::env($this->cleanEnv([
            'DB_PORT' => '5432', 'DB_DATABASE' => 'x', 'DB_USERNAME' => 'x',
        ]))->run(['bash', $this->backupScript]);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('DB_HOST', $this->combinedOutput($result));
    }

    public function test_backup_fails_loudly_against_an_unreachable_host(): void
    {
        $result = Process::env($this->cleanEnv([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1', // nothing listens on port 1
            'DB_DATABASE' => 'nonexistent',
            'DB_USERNAME' => 'nobody',
        ]))->timeout(15)->run(['bash', $this->backupScript, sys_get_temp_dir()]);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('FAILED', $this->combinedOutput($result));
    }

    // ── Verify script fails loudly on a missing/empty file ───────────────────

    public function test_verify_fails_loudly_on_a_missing_file(): void
    {
        $result = Process::run(['bash', $this->verifyScript, '/tmp/does-not-exist-'.uniqid().'.sql.gz']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('FAILED', $this->combinedOutput($result));
    }

    public function test_verify_fails_loudly_on_an_empty_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas-empty-dump-');
        file_put_contents($path, '');

        try {
            $result = Process::run(['bash', $this->verifyScript, $path]);

            $this->assertFalse($result->successful());
            $this->assertStringContainsString('FAILED', $this->combinedOutput($result));
        } finally {
            @unlink($path);
        }
    }

    // ── Restore script never proceeds without explicit, matching confirmation ─

    public function test_restore_refuses_without_yes_when_stdin_is_empty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas-dump-');
        file_put_contents($path, "-- not a real dump\n");

        try {
            $result = Process::env($this->cleanEnv([
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'irrelevant', 'DB_USERNAME' => 'irrelevant',
            ]))->input('')->run(['bash', $this->restoreScript, $path]);

            $this->assertFalse($result->successful());
            $this->assertStringContainsString('ABORTED', $this->combinedOutput($result));
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_refuses_yes_with_a_mismatched_confirm_database(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas-dump-');
        file_put_contents($path, "-- not a real dump\n");

        try {
            $result = Process::env($this->cleanEnv([
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'the-real-target', 'DB_USERNAME' => 'irrelevant',
            ]))->run(['bash', $this->restoreScript, $path, '--yes', '--confirm-database=wrong-name']);

            $this->assertFalse($result->successful());
            $output = $this->combinedOutput($result);
            $this->assertStringContainsString('FAILED', $output);
            $this->assertStringContainsString('confirm-database', $output);
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_refuses_a_gpg_encrypted_file_without_decryption_first(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'atlas-dump-').'.gpg';
        file_put_contents($path, 'not actually encrypted, just needs the extension');

        try {
            $result = Process::env($this->cleanEnv([
                'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
                'DB_DATABASE' => 'irrelevant', 'DB_USERNAME' => 'irrelevant',
            ]))->run(['bash', $this->restoreScript, $path, '--yes', '--confirm-database=irrelevant']);

            $this->assertFalse($result->successful());
            $this->assertStringContainsString('gpg-encrypted', $this->combinedOutput($result));
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_fails_loudly_on_a_missing_dump_file(): void
    {
        $result = Process::env($this->cleanEnv([
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'irrelevant', 'DB_USERNAME' => 'irrelevant',
        ]))->run(['bash', $this->restoreScript, '/tmp/does-not-exist-'.uniqid().'.sql.gz', '--yes', '--confirm-database=irrelevant']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('FAILED', $this->combinedOutput($result));
    }
}
