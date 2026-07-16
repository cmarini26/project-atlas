<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * `.env.example` is the canonical list of every environment variable a
 * production deploy needs to consider — docs/ops/Customer-1-Launch-Runbook.md
 * Phase 2 builds its production `.env` template directly from these names.
 * A variable a real deploy must set but that isn't even mentioned here is
 * easy to silently forget. This guards the one gap found while auditing
 * the runbook against this file (SESSION_SECURE_COOKIE) so it can't quietly
 * disappear again, without asserting on unrelated file formatting.
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
}
