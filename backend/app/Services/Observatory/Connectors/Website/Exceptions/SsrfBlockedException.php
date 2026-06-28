<?php

namespace App\Services\Observatory\Connectors\Website\Exceptions;

use RuntimeException;

final class SsrfBlockedException extends RuntimeException
{
    public static function blockedUrl(string $url, string $reason): self
    {
        return new self("SSRF blocked — URL '{$url}' rejected: {$reason}");
    }
}
