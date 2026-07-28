<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $processed_at
 */
class SourceAsset extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    public const TYPES = [
        'product_service', 'promotion_event', 'photo_video',
        'document_case_study', 'webpage_blog_post', 'brand_material',
    ];

    protected $fillable = [
        'company_id', 'observation_id', 'type', 'title', 'description',
        'source_url', 'media_path', 'media_mime_type', 'metadata', 'status', 'processing_error',
        'media_fingerprint', 'content_fingerprint', 'starts_at', 'ends_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Observation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'subject_id')->where('subject_type', 'source_asset');
    }
}
