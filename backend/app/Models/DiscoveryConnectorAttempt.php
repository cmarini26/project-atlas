<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\DiscoveryAttemptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per declared MarketingChannel that Discovery actually attempts to
 * observe — declared assets with no runnable connector never get a row here
 * at all (they simply remain is_connected: false). See
 * docs/specs/Business-Discovery-Onboarding.md §4.3.
 *
 * @property DiscoveryAttemptStatus $status
 */
class DiscoveryConnectorAttempt extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'discovery_run_id',
        'company_id',
        'marketing_channel_id',
        'integration_id',
        'connector_type',
        'status',
        'attempt_count',
        'observation_id',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiscoveryAttemptStatus::class,
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DiscoveryRun, $this> */
    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class);
    }

    /** @return BelongsTo<MarketingChannel, $this> */
    public function marketingChannel(): BelongsTo
    {
        return $this->belongsTo(MarketingChannel::class);
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<Observation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}
