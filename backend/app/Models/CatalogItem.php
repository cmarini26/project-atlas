<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogItem extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'catalog_id',
        'company_id',
        'external_id',
        'canonical_url',
        'source_url',
        'title',
        'description',
        'status',
        'price',
        'media',
        'metadata',
        'promoted_at',
        'featured_at',
        'expires_at',
        'sold_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price' => 'decimal:2',
        'media' => 'array',
        'metadata' => 'array',
        'promoted_at' => 'datetime',
        'featured_at' => 'datetime',
        'expires_at' => 'datetime',
        'sold_at' => 'datetime',
    ];

    /** @return BelongsTo<Catalog, $this> */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /** @param Builder<CatalogItem> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
