<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionMetric extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'execution_id',
        'campaign_id',
        'channel_type',
        'provider_type',
        'platform_id',
        'retrieved_at',
        'window_closes_at',
        'is_final',
        'raw',
        'metrics',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'retrieved_at' => 'datetime',
        'window_closes_at' => 'datetime',
        'is_final' => 'boolean',
        'raw' => 'array',
        'metrics' => 'array',
    ];

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @param Builder<self> $query */
    public function scopeForCampaign(Builder $query, string $campaignId): void
    {
        $query->where('campaign_id', $campaignId);
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('is_final', false);
    }
}
