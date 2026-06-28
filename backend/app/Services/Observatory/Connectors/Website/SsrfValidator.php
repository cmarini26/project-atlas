<?php

namespace App\Services\Observatory\Connectors\Website;

use App\Services\Observatory\Connectors\Website\Exceptions\SsrfBlockedException;

/**
 * Validates that a URL is safe to fetch — blocking private IP ranges,
 * loopback, link-local, and cloud metadata endpoints.
 *
 * All validation happens before any outbound connection is made.
 */
final class SsrfValidator
{
    /**
     * CIDR blocks that must never be reached.
     * Covers loopback, private, link-local (incl. AWS/GCP metadata), and IPv4-mapped IPv6.
     *
     * @var list<string>
     */
    private const BLOCKED_CIDRS = [
        '127.0.0.0/8',       // loopback
        '10.0.0.0/8',        // private class A
        '172.16.0.0/12',     // private class B
        '192.168.0.0/16',    // private class C
        '169.254.0.0/16',    // link-local / cloud metadata (AWS 169.254.169.254, GCP, Azure)
        '0.0.0.0/8',         // "this" network
        '100.64.0.0/10',     // shared address space (RFC 6598)
        '192.0.0.0/24',      // IETF protocol assignments
        '192.0.2.0/24',      // TEST-NET-1
        '198.18.0.0/15',     // benchmarking
        '198.51.100.0/24',   // TEST-NET-2
        '203.0.113.0/24',    // TEST-NET-3
        '240.0.0.0/4',       // reserved (class E)
        '255.255.255.255/32', // broadcast
    ];

    /**
     * Hostname suffixes that always resolve to blocked addresses.
     * Defence-in-depth — the IP check catches these too, but fail fast on hostname.
     *
     * @var string[]
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * @throws SsrfBlockedException
     */
    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            throw SsrfBlockedException::blockedUrl($url, 'URL could not be parsed or has no host');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw SsrfBlockedException::blockedUrl($url, "scheme '{$scheme}' is not permitted — only http and https are allowed");
        }

        $host = strtolower($parsed['host']);

        // Strip surrounding IPv6 brackets if present.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $this->validateHost($url, $host);
    }

    /**
     * @throws SsrfBlockedException
     */
    private function validateHost(string $url, string $host): void
    {
        // Reject well-known blocked hostnames before DNS.
        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw SsrfBlockedException::blockedUrl($url, "hostname '{$host}' is blocked");
        }

        // If the host is already an IPv6 address, validate it directly.
        if ($this->isIpv6Address($host)) {
            $this->validateIpv6($url, $host);

            return;
        }

        // If the host is already an IPv4 address, validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $this->validateIpv4($url, $host);

            return;
        }

        // DNS resolution — all returned addresses must be public.
        $addresses = $this->resolveHost($url, $host);

        foreach ($addresses as $ip) {
            if ($this->isIpv6Address($ip)) {
                $this->validateIpv6($url, $ip);
            } else {
                $this->validateIpv4($url, $ip);
            }
        }
    }

    /**
     * @throws SsrfBlockedException
     */
    private function validateIpv4(string $url, string $ip): void
    {
        $long = ip2long($ip);

        if ($long === false) {
            throw SsrfBlockedException::blockedUrl($url, "'{$ip}' is not a valid IPv4 address");
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            [$network, $bits] = explode('/', $cidr, 2);
            $mask = $bits === '32' ? 0xFFFFFFFF : ~((1 << (32 - (int) $bits)) - 1);
            $networkLong = ip2long($network);

            if ($networkLong !== false && ($long & $mask) === ($networkLong & $mask)) {
                throw SsrfBlockedException::blockedUrl($url, "IP address {$ip} falls within blocked range {$cidr}");
            }
        }
    }

    /**
     * @throws SsrfBlockedException
     */
    private function validateIpv6(string $url, string $ip): void
    {
        // Expand the address for comparison.
        $expanded = inet_pton($ip);

        if ($expanded === false) {
            throw SsrfBlockedException::blockedUrl($url, "'{$ip}' is not a valid IPv6 address");
        }

        // Loopback: ::1
        if ($ip === '::1' || $expanded === inet_pton('::1')) {
            throw SsrfBlockedException::blockedUrl($url, "IPv6 loopback address '{$ip}' is blocked");
        }

        // Link-local: fe80::/10
        $firstTwo = unpack('n', substr($expanded, 0, 2));
        if ($firstTwo !== false && ($firstTwo[1] & 0xFFC0) === 0xFE80) {
            throw SsrfBlockedException::blockedUrl($url, "IPv6 link-local address '{$ip}' is blocked");
        }

        // Unique local: fc00::/7 (includes fd00::/8)
        $firstByte = ord($expanded[0]);
        if (($firstByte & 0xFE) === 0xFC) {
            throw SsrfBlockedException::blockedUrl($url, "IPv6 unique-local address '{$ip}' is blocked");
        }

        // Unspecified: ::
        if ($expanded === inet_pton('::')) {
            throw SsrfBlockedException::blockedUrl($url, "IPv6 unspecified address '::' is blocked");
        }

        // IPv4-mapped: ::ffff:0:0/96
        $v4mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF";
        if (str_starts_with($expanded, $v4mapped)) {
            $v4 = inet_ntop(substr($expanded, 12));
            if ($v4 !== false) {
                $this->validateIpv4($url, $v4);
            }
        }
    }

    /**
     * @return string[]
     *
     * @throws SsrfBlockedException
     */
    private function resolveHost(string $url, string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        // dns_get_record returns false on complete failure (NXDOMAIN, etc.).
        if ($records === false || $records === []) {
            // Fall back to gethostbynamel for environments where dns_get_record is restricted.
            $ipv4 = @gethostbynamel($host);

            if ($ipv4 === false || $ipv4 === []) {
                throw SsrfBlockedException::blockedUrl($url, "hostname '{$host}' could not be resolved");
            }

            return $ipv4;
        }

        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        if ($addresses === []) {
            throw SsrfBlockedException::blockedUrl($url, "hostname '{$host}' resolved to no usable IP addresses");
        }

        return $addresses;
    }

    private function isIpv6Address(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
