<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'knowledge_entries';

    protected $fillable = [
        'company_id',
        'type',
        'subject',
        'body',
        'structured',
        'source_fact_ids',
        'confidence',
        'is_active',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'structured' => 'array',
            'source_fact_ids' => 'array',
            'is_active' => 'boolean',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @param Builder<Knowledge> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
