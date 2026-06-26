<?php

namespace App\Domain\Shared\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applies a company_id WHERE clause to every query on tenant models.
 * Only active when 'current_company_id' is bound in the container —
 * this keeps the scope a no-op in CLI, test, and admin contexts that
 * have not set a tenant.
 */
/** @implements Scope<Model> */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->has('current_company_id')) {
            $builder->where(
                $model->qualifyColumn('company_id'),
                app('current_company_id')
            );
        }
    }
}
