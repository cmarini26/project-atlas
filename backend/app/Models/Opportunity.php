<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Opportunity extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'subject_type',
        'subject_id',
        'type',
        'title',
        'description',
        'relevance_score',
        'timing_score',
        'confidence_score',
        'urgency_score',
        'composite_score',
        'ai_detected',
        'status',
        'expires_at',
        'detected_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'relevance_score' => 'integer',
        'timing_score' => 'integer',
        'confidence_score' => 'integer',
        'urgency_score' => 'integer',
        'composite_score' => 'integer',
        'ai_detected' => 'boolean',
        'expires_at' => 'datetime',
        'detected_at' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasOne<Decision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(Decision::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @param Builder<Opportunity> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function select(): void
    {
        $this->update(['status' => 'selected']);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }
}
