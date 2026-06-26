<?php

namespace App\Services\Publishing\Contracts;

use App\Models\Execution;

interface SupportsRollback
{
    /**
     * Remove the published content from the platform.
     * Only called when Execution.status = 'completed' and result.platform_id is present.
     *
     * Returns true on success, false on platform-side failure (already deleted, etc).
     */
    public function rollback(Execution $execution): bool;
}
