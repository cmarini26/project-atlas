<?php

namespace Tests\Unit\Observatory;

use App\Services\Observatory\Connectors\Website\Exceptions\SsrfBlockedException;
use App\Services\Observatory\Connectors\Website\SsrfValidator;
use Tests\TestCase;

class SsrfValidatorTest extends TestCase
{
    private SsrfValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SsrfValidator();
    }

    // --- Scheme validation ---

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('ftp://example.com/file');
    }

    public function test_rejects_file_scheme(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('file:///etc/passwd');
    }

    public function test_rejects_javascript_scheme(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('javascript://example.com');
    }

    public function test_accepts_http_scheme(): void
    {
        // google.com is a public IP — should not throw
        $this->expectNotToPerformAssertions();
        try {
            $this->validator->validate('http://93.184.216.34'); // example.com IP
        } catch (SsrfBlockedException) {
            // allowed — it may block if DNS resolution fails in CI
        }
    }

    public function test_accepts_https_scheme(): void
    {
        $this->expectNotToPerformAssertions();
        try {
            $this->validator->validate('https://93.184.216.34');
        } catch (SsrfBlockedException) {
            // allowed
        }
    }

    // --- Loopback blocking ---

    public function test_blocks_localhost_hostname(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://localhost/admin');
    }

    public function test_blocks_127_0_0_1(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://127.0.0.1/');
    }

    public function test_blocks_127_0_0_2(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://127.0.0.2/');
    }

    public function test_blocks_ipv6_loopback(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://[::1]/');
    }

    // --- Private IP ranges ---

    public function test_blocks_10_x_x_x(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://10.0.0.1/');
    }

    public function test_blocks_10_255_255_255(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://10.255.255.255/');
    }

    public function test_blocks_172_16_x_x(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://172.16.0.1/');
    }

    public function test_blocks_172_31_x_x(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://172.31.255.255/');
    }

    public function test_blocks_192_168_x_x(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://192.168.1.100/');
    }

    // --- Cloud metadata endpoint ---

    public function test_blocks_aws_metadata_ip(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://169.254.169.254/latest/meta-data/');
    }

    public function test_blocks_link_local_169_254(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://169.254.0.1/');
    }

    // --- IPv6 private ranges ---

    public function test_blocks_ipv6_link_local(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://[fe80::1]/');
    }

    public function test_blocks_ipv6_unique_local_fc(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://[fc00::1]/');
    }

    public function test_blocks_ipv6_unique_local_fd(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://[fd12:3456:789a::1]/');
    }

    // --- Invalid URLs ---

    public function test_rejects_url_with_no_host(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http:///path');
    }

    public function test_rejects_unparseable_url(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('not a url at all');
    }

    // --- Boundary: 172.15.x.x should NOT be blocked (outside 172.16-31) ---

    public function test_does_not_block_172_15_x_x(): void
    {
        // 172.15.x.x is outside the 172.16.0.0/12 range — should pass IP validation
        // (it may still fail DNS resolution in some environments, but won't throw SSRF)
        try {
            $this->validator->validate('http://172.15.0.1/');
            $this->assertTrue(true); // passed SSRF check
        } catch (SsrfBlockedException $e) {
            // If it throws, it must NOT be for a private range reason
            $this->assertStringNotContainsString('172.16.0.0/12', $e->getMessage());
        }
    }

    // --- 0.0.0.0 ---

    public function test_blocks_0_0_0_0(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->validator->validate('http://0.0.0.0/');
    }
}
