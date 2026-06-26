<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fact extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'observation_id',
        'key',
        'value',
        'data_type',
        'confidence',
        'is_current',
        'superseded_by_id',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_current' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Observation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    /** @return BelongsTo<Fact, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'superseded_by_id');
    }

    /** @param Builder<Fact> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
