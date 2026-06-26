<?php

namespace Tests\Feature\Publishing\Email;

use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Email\Exceptions\UnknownEmailProviderException;
use App\Services\Publishing\Email\FakeEmailProvider;
use App\Services\Publishing\Email\LogEmailProvider;
use Tests\TestCase;

class EmailProviderRegistryTest extends TestCase
{
    public function test_resolves_registered_provider_by_type(): void
    {
        $registry = new EmailProviderRegistry();
        $fake = new FakeEmailProvider();
        $registry->register($fake);

        $resolved = $registry->for('anything');

        $this->assertSame($fake, $resolved);
    }

    public function test_resolves_log_provider_for_log_type(): void
    {
        $registry = new EmailProviderRegistry();
        $log = new LogEmailProvider();
        $registry->register($log);

        $resolved = $registry->for('log');

        $this->assertSame($log, $resolved);
    }

    public function test_throws_when_no_provider_supports_type(): void
    {
        $this->expectException(UnknownEmailProviderException::class);

        $registry = new EmailProviderRegistry();
        $log = new LogEmailProvider(); // only supports 'log'
        $registry->register($log);

        $registry->for('postmark');
    }

    public function test_all_returns_registered_providers(): void
    {
        $registry = new EmailProviderRegistry();
        $log = new LogEmailProvider();
        $fake = new FakeEmailProvider();

        $registry->register($log);
        $registry->register($fake);

        $this->assertCount(2, $registry->all());
    }

    public function test_resolves_first_matching_provider(): void
    {
        $registry = new EmailProviderRegistry();
        $first = new FakeEmailProvider();
        $second = new FakeEmailProvider();

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($first, $registry->for('any'));
    }

    public function test_unknown_provider_exception_is_not_retryable(): void
    {
        $registry = new EmailProviderRegistry();

        try {
            $registry->for('nonexistent');
            $this->fail('Expected UnknownEmailProviderException.');
        } catch (UnknownEmailProviderException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }
}
