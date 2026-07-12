<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's current (or superseded) score for one Marketing Health
 * dimension — Milestone 13 Phase 1. Mirrors Fact's current-value-with-
 * supersession pattern exactly: a re-computation never deletes or updates
 * the prior row, it creates a new one and flips is_current on the old one.
 */
class MarketingHealthScore extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'dimension',
        'score',
        'confidence',
        'evidence',
        'computed_at',
        'is_current',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'computed_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<MarketingHealthScore, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(MarketingHealthScore::class, 'superseded_by_id');
    }

    /** @param  Builder<MarketingHealthScore>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
