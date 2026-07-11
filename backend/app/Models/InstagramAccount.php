<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest known profile snapshot for a company's connected Instagram
 * account. Milestone 12 Phase 1 (Instagram Observation, Beta) — one account
 * per company; multiple accounts are out of scope. See
 * app/Services/Observatory/InstagramAccountService.php for how this is kept
 * in sync with each Instagram Observation.
 */
class InstagramAccount extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'integration_id',
        'account_id',
        'username',
        'display_name',
        'profile_picture_url',
        'bio',
        'website',
        'follower_count',
        'following_count',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'follower_count' => 'integer',
            'following_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
