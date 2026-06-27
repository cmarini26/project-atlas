<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Learning extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'source_type',
        'source_id',
        'subject_type',
        'subject_id',
        'signal',
        'value',
        'applied_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'array',
        'applied_at' => 'datetime',
    ];

    /** @param Builder<self> $query */
    public function scopeUnapplied(Builder $query): void
    {
        $query->whereNull('applied_at');
    }
}
