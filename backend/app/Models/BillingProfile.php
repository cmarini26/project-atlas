<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The minimum billing truth Atlas persists for a company: Stripe customer +
 * subscription linkage, the last-known subscription status, and a beta-safe
 * operator override. Stripe remains the source of truth — these fields are a
 * cache updated from webhooks (CM-75) and read by the access gate (CM-78).
 *
 * @property-read bool $grants_access
 */
class BillingProfile extends Model
{
    use BelongsToCompany, HasUlids;

    /** Stripe subscription statuses that entitle a company to paid access. */
    public const ACCESS_STATUSES = ['trialing', 'active', 'past_due'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'price_id',
        'current_period_ends_at',
        'cancel_at_period_end',
        'beta_access_override',
    ];

    protected function casts(): array
    {
        return [
            'current_period_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'beta_access_override' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasStripeCustomer(): bool
    {
        return $this->stripe_customer_id !== null && $this->stripe_customer_id !== '';
    }

    public function hasSubscription(): bool
    {
        return $this->stripe_subscription_id !== null && $this->stripe_subscription_id !== '';
    }

    /** Whether this company may use paid Atlas features right now. */
    public function grantsAccess(): bool
    {
        return $this->beta_access_override
            || in_array($this->subscription_status, self::ACCESS_STATUSES, true);
    }
}
