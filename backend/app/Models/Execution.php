<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Execution extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'campaign_id',
        'content_asset_id',
        'channel_id',
        'status',
        'scheduled_at',
        'executed_at',
        'completed_at',
        'attempts',
        'last_error',
        'idempotency_key',
        'result',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'result' => 'array',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<ContentAsset, $this> */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'content_asset_id');
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return HasMany<ExecutionAttempt, $this> */
    public function attemptLogs(): HasMany
    {
        return $this->hasMany(ExecutionAttempt::class);
    }

    /** @return HasOne<ExecutionMetric, $this> */
    public function metric(): HasOne
    {
        return $this->hasOne(ExecutionMetric::class);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }
}
