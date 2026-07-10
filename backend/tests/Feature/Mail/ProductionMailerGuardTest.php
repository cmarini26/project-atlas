<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\ProductionMailerGuard;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 6 (real transactional email) — see
 * docs/plans/Critical-Production-Blockers.md. `MAIL_MAILER=log`/`array`
 * never throws — it "succeeds" by writing to a log file instead of
 * delivering — so this has to be checked explicitly, not caught as an
 * exception.
 */
class ProductionMailerGuardTest extends TestCase
{
    private ProductionMailerGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ProductionMailerGuard();
    }

    public function test_production_with_log_mailer_is_misconfigured(): void
    {
        $this->assertTrue($this->guard->isMisconfigured('production', 'log'));
    }

    public function test_production_with_array_mailer_is_misconfigured(): void
    {
        $this->assertTrue($this->guard->isMisconfigured('production', 'array'));
    }

    public function test_production_with_postmark_mailer_is_not_misconfigured(): void
    {
        $this->assertFalse($this->guard->isMisconfigured('production', 'postmark'));
    }

    public function test_production_with_smtp_mailer_is_not_misconfigured(): void
    {
        $this->assertFalse($this->guard->isMisconfigured('production', 'smtp'));
    }

    public function test_local_with_log_mailer_is_not_misconfigured(): void
    {
        $this->assertFalse($this->guard->isMisconfigured('local', 'log'));
    }

    public function test_testing_with_log_mailer_is_not_misconfigured(): void
    {
        $this->assertFalse($this->guard->isMisconfigured('testing', 'log'));
    }

    public function test_staging_with_log_mailer_is_not_misconfigured(): void
    {
        // Only 'production' triggers the guard — staging environments are
        // expected to use log/array intentionally.
        $this->assertFalse($this->guard->isMisconfigured('staging', 'log'));
    }
}
