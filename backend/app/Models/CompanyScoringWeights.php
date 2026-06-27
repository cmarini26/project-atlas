<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CompanyScoringWeights extends Model
{
    use BelongsToCompany, HasUlids;

    const UPDATED_AT = null;

    protected $table = 'company_scoring_weights';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'weights',
        'version',
        'is_current',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'weights' => 'array',
        'version' => 'integer',
        'is_current' => 'boolean',
    ];

    /** @param Builder<CompanyScoringWeights> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * @return array<string, float>
     */
    public function typeModifiers(): array
    {
        /** @var array<string, mixed> $weights */
        $weights = $this->weights ?? [];

        /** @var array<string, float> $modifiers */
        $modifiers = $weights['type_modifiers'] ?? [];

        return $modifiers;
    }

    public static function defaultWeights(): self
    {
        $model = new self();
        $model->weights = ['type_modifiers' => []];
        $model->version = 0;
        $model->is_current = true;

        return $model;
    }
}
