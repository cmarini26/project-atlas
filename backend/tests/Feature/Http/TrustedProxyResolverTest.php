<?php

namespace Tests\Feature\Http;

use App\Services\Http\TrustedProxyResolver;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 7 (production infrastructure
 * configuration) — see docs/plans/Critical-Production-Blockers.md and
 * docs/deployment/Production-Topology.md.
 */
class TrustedProxyResolverTest extends TestCase
{
    private TrustedProxyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TrustedProxyResolver();
    }

    public function test_null_resolves_to_trusting_no_proxies(): void
    {
        $this->assertNull($this->resolver->resolve(null));
    }

    public function test_empty_string_resolves_to_trusting_no_proxies(): void
    {
        $this->assertNull($this->resolver->resolve(''));
    }

    public function test_wildcard_resolves_to_the_wildcard_literal(): void
    {
        $this->assertSame('*', $this->resolver->resolve('*'));
    }

    public function test_a_single_ip_resolves_to_a_one_item_array(): void
    {
        $this->assertSame(['10.0.0.5'], $this->resolver->resolve('10.0.0.5'));
    }

    public function test_a_comma_separated_list_resolves_to_an_array(): void
    {
        $this->assertSame(
            ['10.0.0.5', '10.0.0.6'],
            $this->resolver->resolve('10.0.0.5,10.0.0.6'),
        );
    }

    public function test_whitespace_around_entries_is_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.5', '10.0.0.6'],
            $this->resolver->resolve('10.0.0.5, 10.0.0.6'),
        );
    }

    public function test_a_cidr_range_is_preserved_as_is(): void
    {
        $this->assertSame(['10.0.0.0/24'], $this->resolver->resolve('10.0.0.0/24'));
    }
}
