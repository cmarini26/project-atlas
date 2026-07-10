<?php

namespace App\Services\Mail;

/**
 * Detects the exact silent failure the audit flagged: `MAIL_MAILER=log` (or
 * `array`) never throws — it "succeeds" by writing the email to a log file
 * instead of delivering it. A try/catch around the send call can't detect
 * that, since nothing fails technically; this has to be checked explicitly,
 * before attempting to send. See docs/plans/Critical-Production-Blockers.md,
 * Blocker 6.
 */
class ProductionMailerGuard
{
    private const NON_DELIVERY_MAILERS = ['log', 'array'];

    public function isMisconfigured(string $environment, string $mailer): bool
    {
        return $environment === 'production' && in_array($mailer, self::NON_DELIVERY_MAILERS, true);
    }
}
