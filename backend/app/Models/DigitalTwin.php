<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DigitalTwin extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'status',
        'health_score',
        'last_observed_at',
        'last_enriched_at',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'health_score' => 'integer',
        'last_observed_at' => 'datetime',
        'last_enriched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInitializing(): bool
    {
        return $this->status === 'initializing';
    }
}
