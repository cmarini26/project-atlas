<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image generation attempt for a campaign. Doubles as the rolling-window
 * ledger the per-company generation cap is enforced against, and as the
 * per-campaign cost/count record.
 */
class CampaignImageGeneration extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'campaign_brief_id',
        'status',
        'prompt',
        'provider',
        'model',
        'media_path',
        'width',
        'height',
        'cost_usd',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'cost_usd' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<CampaignBrief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(CampaignBrief::class, 'campaign_brief_id');
    }

    public function mediaUrl(): ?string
    {
        return $this->media_path !== null ? asset("storage/{$this->media_path}") : null;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
