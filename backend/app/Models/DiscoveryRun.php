<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\DiscoveryStage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per Business Discovery orchestration run — a pure observability
 * and orchestration-trigger layer that never gates the real Observation →
 * Fact → Knowledge → Opportunity → Recommendation pipeline. See
 * docs/specs/Business-Discovery-Onboarding.md §4.3.
 *
 * @property DiscoveryStage $stage
 */
class DiscoveryRun extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'stage',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => DiscoveryStage::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<DiscoveryConnectorAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(DiscoveryConnectorAttempt::class);
    }
}
