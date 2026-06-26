<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recommendation extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'decision_id',
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

    protected function casts(): array
    {
        return [
            'rationale_display' => 'array',
            'expected_impact' => 'array',
            'viewed_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
