<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recommendation extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'decision_id',
        'campaign_id',
        'campaign_type',
        'title',
        'summary',
        'rationale_display',
        'confidence_score',
        'expected_impact',
        'status',
        'viewed_at',
        'responded_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rationale_display' => 'array',
        'expected_impact' => 'array',
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    /** @return BelongsTo<Decision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
