<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'industry',
        'website_url',
        'brand',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'brand' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });
    }

    /** @return HasOne<DigitalTwin, $this> */
    public function digitalTwin(): HasOne
    {
        return $this->hasOne(DigitalTwin::class);
    }

    /** @return HasOne<Catalog, $this> */
    public function catalog(): HasOne
    {
        return $this->hasOne(Catalog::class);
    }

    /** @return HasMany<CompanyMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    /** @return HasMany<Integration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    /** @return HasMany<Observation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /** @return HasMany<Fact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    /** @return HasMany<Knowledge, $this> */
    public function knowledge(): HasMany
    {
        return $this->hasMany(Knowledge::class);
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /** @return HasMany<Decision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    /** @return HasMany<MarketingChannel, $this> */
    public function marketingChannels(): HasMany
    {
        return $this->hasMany(MarketingChannel::class);
    }
}
