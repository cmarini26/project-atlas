<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * SCRUM-37 (production configuration sanity check). The 'publishing' log
 * channel (storage/logs/publishing.log — every WordPress/Meta/Postmark send
 * attempt) previously used the 'single' driver, which never rotates,
 * independent of whatever LOG_STACK/LOG_DAILY_DAYS is configured for the
 * main app log — a real, previously-flagged (Production-Deployment-Audit.md,
 * 2026-07-10) but still-open gap: switching LOG_STACK to "daily" in
 * production (per docs/ops/Customer-1-Launch-Runbook.md Phase 2) did
 * nothing to rotate this separate channel. Guards the fix so it can't
 * silently regress back to an unbounded-growth channel.
 */
class LoggingConfigTest extends TestCase
{
    public function test_publishing_channel_rotates_daily_and_respects_the_shared_retention_window(): void
    {
        $channel = config('logging.channels.publishing');

        $this->assertSame('daily', $channel['driver'], 'The publishing log channel must rotate — never "single" (unbounded growth).');
        $this->assertSame(14, $channel['days'], 'Expected the default LOG_DAILY_DAYS retention window.');
    }

    public function test_publishing_channel_level_is_env_driven_not_hardcoded(): void
    {
        config(['logging.channels.publishing.level' => null]);

        // Re-resolve from the actual config file logic by re-reading it
        // fresh, rather than asserting on a value already cached in the
        // container from boot — confirms the *source* reads env(), not a
        // literal string, without relying on a real env override in tests.
        $definition = require base_path('config/logging.php');

        $this->assertSame(
            env('LOG_LEVEL', 'debug'),
            $definition['channels']['publishing']['level'],
            'The publishing channel level must follow LOG_LEVEL, matching the main daily channel, not a hardcoded string.',
        );
    }
}
