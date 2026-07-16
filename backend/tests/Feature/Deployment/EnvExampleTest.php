<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * `.env.example` is the canonical list of every environment variable a
 * production deploy needs to consider — docs/ops/Customer-1-Launch-Runbook.md
 * Phase 2 builds its production `.env` template directly from these names,
 * and docs/deployment/Environment-Variables.md (SCRUM-35) is the full,
 * code-verified inventory this file must stay consistent with. A variable a
 * real deploy must set (or at least know exists) but that isn't even
 * mentioned here is easy to silently forget. This guards every gap found
 * while auditing this file against actual `env()` calls in `config/*.php`
 * so none of them can quietly disappear again, without asserting on
 * unrelated file formatting.
 */
class EnvExampleTest extends TestCase
{
    private function envExampleContents(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    public function test_session_secure_cookie_is_documented(): void
    {
        $this->assertStringContainsString(
            'SESSION_SECURE_COOKIE=',
            $this->envExampleContents(),
            'SESSION_SECURE_COOKIE must be documented in .env.example — config/session.php reads it with no default, '
            .'and it has no effect unless a real deploy explicitly sets it.',
        );
    }

    public function test_documents_other_variables_a_production_deploy_must_set(): void
    {
        $contents = $this->envExampleContents();

        foreach ([
            'TRUSTED_PROXIES=',
            'ERROR_TRACKING_DRIVER=',
            'ERROR_TRACKING_DSN=',
            'POSTMARK_API_KEY=',
            'POSTMARK_MESSAGE_STREAM_ID=',
        ] as $variable) {
            $this->assertStringContainsString($variable, $contents, "{$variable} must remain documented in .env.example.");
        }
    }

    /**
     * SCRUM-35: these three have safe defaults and never needed a different
     * value, but were previously undiscoverable without reading config/*.php
     * directly — see docs/deployment/Environment-Variables.md §2.
     */
    public function test_documents_variables_with_safe_defaults_that_were_previously_undiscoverable(): void
    {
        $contents = $this->envExampleContents();

        foreach ([
            'LOG_DAILY_DAYS=' => 'governs retention once LOG_STACK=daily',
            'DB_QUEUE_RETRY_AFTER=' => 'governs abandoned-job retry timing on the active "database" queue driver',
            'REDIS_CACHE_DB=' => 'keeps the cache Redis DB isolated from the session/default connection',
        ] as $variable => $reason) {
            $this->assertStringContainsString($variable, $contents, "{$variable} must remain documented in .env.example — it {$reason}.");
        }
    }
}
