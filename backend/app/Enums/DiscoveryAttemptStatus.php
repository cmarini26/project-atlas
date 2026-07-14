<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum DiscoveryAttemptStatus: string
{
    use EnumValues;

    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case SkippedNoCredentials = 'skipped_no_credentials';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::SkippedNoCredentials => true,
            self::Pending, self::Running => false,
        };
    }
}
