<?php

namespace App\Domain\Insights\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Multi-branch client sharing toggle (settings.insights.client_sharing, default ON = no effect).
 * When OFF and the signed-in team member is restricted to some branches, clients are visible only if their
 * preferred branch is one of those branches, they have a booking there, or they are brand new (no branch, no
 * bookings yet). Owners/unrestricted users, jobs and the client guard are never affected.
 */
class ClientBranchSharingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $allowed = app(TenantContext::class)->allowedBranchIds();
        if ($allowed === null || ! tenant() || InsightSettings::get('client_sharing', true)) {
            return;
        }
        $allowed = $allowed ?: [0];
        $table = $model->getTable();

        $builder->where(function (Builder $q) use ($allowed, $table) {
            $q->whereIn($table.'.preferred_branch_id', $allowed)
                ->orWhereExists(fn ($b) => $b->selectRaw('1')->from('bookings')->whereColumn('bookings.client_id', $table.'.id')->whereIn('bookings.branch_id', $allowed))
                ->orWhere(fn ($n) => $n->whereNull($table.'.preferred_branch_id')
                    ->whereNotExists(fn ($b) => $b->selectRaw('1')->from('bookings')->whereColumn('bookings.client_id', $table.'.id')));
        });
    }
}
