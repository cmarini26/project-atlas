<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Decision extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'opportunity_id',
        'campaign_type',
        'channel_ids',
        'rationale',
        'confidence_score',
        'expected_outcome',
        'expected_impact',
        'status',
        'prompt_version',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'channel_ids' => 'array',
            'rationale' => 'array',
            'expected_impact' => 'array',
            'confidence_score' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** @return HasOne<Recommendation, $this> */
    public function recommendation(): HasOne
    {
        return $this->hasOne(Recommendation::class);
    }

    /** @return HasOne<Campaign, $this> */
    public function campaign(): HasOne
    {
        return $this->hasOne(Campaign::class);
    }
}
