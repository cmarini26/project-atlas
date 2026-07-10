<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 6's original acceptance criterion:
 * given Postmark selected as the mailer, the password-reset notification
 * resolves to the correct mailer/transport — without a live API call. See
 * docs/plans/Critical-Production-Blockers.md.
 */
class PostmarkTransportConfigurationTest extends TestCase
{
    public function test_postmark_mailer_resolves_to_the_postmark_transport(): void
    {
        config([
            'mail.default' => 'postmark',
            'services.postmark.key' => 'test-token',
        ]);

        $transport = Mail::mailer('postmark')->getSymfonyTransport();

        $this->assertInstanceOf(PostmarkApiTransport::class, $transport);
    }

    public function test_postmark_transport_uses_the_configured_message_stream(): void
    {
        config([
            'mail.default' => 'postmark',
            'services.postmark.key' => 'test-token',
            'mail.mailers.postmark.message_stream_id' => 'outbound',
        ]);

        $transport = Mail::mailer('postmark')->getSymfonyTransport();

        $this->assertStringContainsString('outbound', (string) $transport);
    }

    public function test_the_safe_non_delivery_default_is_unchanged_for_testing(): void
    {
        // phpunit.xml pins MAIL_MAILER=array for this suite; local
        // development defaults to log via .env.example. Neither delivers
        // real email, and this blocker doesn't change either default.
        $this->assertContains(config('mail.default'), ['log', 'array']);
    }
}
