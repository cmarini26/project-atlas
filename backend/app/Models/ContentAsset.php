<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentAsset extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'campaign_id',
        'channel_id',
        'type',
        'title',
        'body',
        'media',
        'metadata',
        'prompt_name',
        'prompt_version',
        'status',
        'scheduled_at',
        'published_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'media' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
