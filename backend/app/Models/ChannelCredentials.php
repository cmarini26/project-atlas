<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ChannelCredentials extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'channel_type',
        'provider_type',
        'credentials',
        'status',
        'expires_at',
        'last_used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'credentials' => 'encrypted',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }
}
