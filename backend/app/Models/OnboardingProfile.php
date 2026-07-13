<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use App\Enums\MarketingFrequency;
use App\Enums\MarketingOwner;
use App\Enums\PrimaryCallToAction;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per company — onboarding-collected business goals and marketing
 * preferences. Milestone 15 Phase 1. Deliberately narrow: this is raw
 * onboarding input, not a Fact/Knowledge-synthesized understanding of the
 * business — see docs/specs/Business-Discovery-Onboarding.md §2.3.
 *
 * @property MarketingFrequency|null $marketing_frequency
 * @property MarketingOwner|null $marketing_owner
 * @property PrimaryCallToAction|null $primary_cta
 */
class OnboardingProfile extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'business_goals',
        'marketing_frequency',
        'marketing_owner',
        'is_seasonal',
        'seasonal_months',
        'primary_cta',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_goals' => 'array',
            'marketing_frequency' => MarketingFrequency::class,
            'marketing_owner' => MarketingOwner::class,
            'is_seasonal' => 'boolean',
            'seasonal_months' => 'array',
            'primary_cta' => PrimaryCallToAction::class,
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
