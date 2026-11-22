<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts branch-owned rows to the branch switcher selection and to the branches
 * the signed-in user is allowed to see (branch managers, stylists, cashiers).
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn($model->branchColumn());

        if ($allowed = $context->allowedBranchIds()) {
            $builder->whereIn($column, $allowed);
        } elseif ($context->allowedBranchIds() === []) {
            $builder->whereRaw('1 = 0');
        }

        if ($branch = $context->branchId()) {
            $builder->where($column, $branch);
        }
    }
}
