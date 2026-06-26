<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Scopes\CompanyScope;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registers the CompanyScope global scope and provides the company() relationship
 * on every tenant-scoped Eloquent model. Add this trait to any model that
 * carries a company_id column.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
