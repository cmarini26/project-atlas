<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'decision_id',
        'recommendation_id',
        'campaign_type',
        'title',
        'strategy',
        'target_audience',
        'positioning',
        'call_to_action',
        'blueprint',
        'blueprint_version',
        'prompt_version',
        'expected_asset_count',
        'generated_asset_count',
        'status',
        'scheduled_start_at',
        'scheduled_end_at',
        'completed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'blueprint' => 'array',
        'expected_asset_count' => 'integer',
        'generated_asset_count' => 'integer',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Decision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /** @return HasMany<ContentAsset, $this> */
    public function contentAssets(): HasMany
    {
        return $this->hasMany(ContentAsset::class);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** @return HasMany<CampaignKpiSnapshot, $this> */
    public function kpiSnapshots(): HasMany
    {
        return $this->hasMany(CampaignKpiSnapshot::class);
    }

    public function allAssetsGenerated(): bool
    {
        return $this->expected_asset_count > 0
            && $this->generated_asset_count >= $this->expected_asset_count;
    }
}
