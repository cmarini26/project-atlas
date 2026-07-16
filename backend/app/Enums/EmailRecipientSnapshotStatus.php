<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * This slice only ever writes Pending (normal) or Skipped (duplicate
 * removed before persisting). Sent/Failed exist so a future slice that
 * wires real per-recipient sending can update these rows in place rather
 * than needing a schema change.
 */
enum EmailRecipientSnapshotStatus: string
{
    use EnumValues;

    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
