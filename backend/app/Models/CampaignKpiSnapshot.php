<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignKpiSnapshot extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'campaign_id',
        'snapshot_type',
        'snapshotted_at',
        'channels_included',
        'expected_impact',
        'actual_kpis',
        'performance_rating',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'snapshotted_at' => 'datetime',
        'channels_included' => 'array',
        'expected_impact' => 'array',
        'actual_kpis' => 'array',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @param Builder<self> $query */
    public function scopeFinal(Builder $query): void
    {
        $query->where('snapshot_type', 'final');
    }
}
