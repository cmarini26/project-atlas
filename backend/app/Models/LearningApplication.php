<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningApplication extends Model
{
    use BelongsToCompany, HasUlids;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'learning_id',
        'effects',
        'rolled_back_at',
        'rollback_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'effects' => 'array',
        'rolled_back_at' => 'datetime',
    ];

    /** @return BelongsTo<Learning, $this> */
    public function learning(): BelongsTo
    {
        return $this->belongsTo(Learning::class);
    }

    public function isRolledBack(): bool
    {
        return $this->rolled_back_at !== null;
    }
}
