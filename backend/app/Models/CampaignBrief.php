<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CampaignBrief extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'title',
        'goal',
        'objective',
        'audience',
        'guidance',
        'campaign_type',
        'channel_ids',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'channel_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<SourceAsset, $this> */
    public function sourceAssets(): BelongsToMany
    {
        return $this->belongsToMany(SourceAsset::class, 'campaign_brief_source_asset');
    }

    /** @return HasMany<CampaignImageGeneration, $this> */
    public function imageGenerations(): HasMany
    {
        return $this->hasMany(CampaignImageGeneration::class);
    }

    /** @return MorphOne<Opportunity, $this> */
    public function opportunity(): MorphOne
    {
        return $this->morphOne(Opportunity::class, 'subject');
    }

    /** @return HasOne<Campaign, $this> */
    public function campaign(): HasOne
    {
        return $this->hasOne(Campaign::class);
    }
}
