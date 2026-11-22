<?php

namespace App\Domain\Marketing\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * Translates segment rules into a Client query.
 *   {"match":"all"|"any","conditions":[{"field":"not_visited_days","value":60}, ...]}
 * Money values are entered in major units (e.g. 1500 = QAR 1,500) and converted to minor units here.
 * Tables from parallel modules (sales, orders, client_looks) are guarded with Schema::hasTable.
 */
class SegmentQuery
{
    public const FIELDS = [
        'visited_within_days', 'not_visited_days', 'spent_over', 'spent_under', 'visits_at_least', 'birthday_this_month',
        'bought_service', 'bought_product', 'branch', 'gender', 'tags', 'loyalty_tier', 'ai_studio_users', 'has_consent', 'vip', 'source',
    ];

    public function apply(Builder $query, array $rules): Builder
    {
        $conditions = array_values(array_filter($rules['conditions'] ?? [], fn ($c) => ! empty($c['field'])));
        if (! $conditions) {
            return $query;
        }
        $any = ($rules['match'] ?? 'all') === 'any';

        return $query->where(function (Builder $outer) use ($conditions, $any) {
            foreach ($conditions as $condition) {
                $method = $any ? 'orWhere' : 'where';
                $outer->{$method}(fn (Builder $q) => $this->condition($q, $condition['field'], $condition['value'] ?? null));
            }
        });
    }

    protected function condition(Builder $q, string $field, mixed $value): void
    {
        $int = (int) (is_array($value) ? 0 : $value);
        $ids = array_values(array_filter(array_map('intval', (array) $value)));
        $currency = tenant()?->currency ?? 'QAR';

        match ($field) {
            'visited_within_days' => $q->where('last_visit_at', '>=', now()->subDays(max(1, $int))),
            'not_visited_days' => $q->where(fn ($w) => $w->where('last_visit_at', '<', now()->subDays(max(1, $int)))->orWhere(fn ($n) => $n->whereNull('last_visit_at')->where('visits_count', '>', 0))),
            'spent_over' => $q->where('lifetime_value', '>', Money::fromDecimal((string) ($value ?: 0), $currency)->amount),
            'spent_under' => $q->where('lifetime_value', '<', Money::fromDecimal((string) ($value ?: 0), $currency)->amount),
            'visits_at_least' => $q->where('visits_count', '>=', $int),
            'birthday_this_month' => $q->whereNotNull('birthday')->whereMonth('birthday', now(tenant()?->timezone ?? 'UTC')->month),
            'bought_service' => $q->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('bookings')
                ->join('booking_items', 'booking_items.booking_id', '=', 'bookings.id')
                ->whereColumn('bookings.client_id', 'clients.id')
                ->where('bookings.status', BookingStatus::Completed->value)
                ->whereIn('booking_items.service_id', $ids ?: [0])),
            'bought_product' => $this->boughtProduct($q, $ids),
            'branch' => $q->where(fn ($w) => $w->whereIn('preferred_branch_id', $ids ?: [0])
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('bookings')->whereColumn('bookings.client_id', 'clients.id')->whereIn('bookings.branch_id', $ids ?: [0]))),
            'gender' => $q->where('gender', $value),
            'tags' => $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $ids ?: [0])),
            'loyalty_tier' => in_array('silver', (array) $value, true)
                ? $q->where(fn ($w) => $w->whereIn('loyalty_tier', (array) $value)->orWhereNull('loyalty_tier'))
                : $q->whereIn('loyalty_tier', (array) $value),
            'ai_studio_users' => Schema::hasTable('client_looks')
                ? $q->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('client_looks')->whereColumn('client_looks.client_id', 'clients.id'))
                : $q->whereRaw('1 = 0'),
            'has_consent' => $q->withConsent((string) $value),
            'vip' => $q->where('is_vip', true),
            'source' => $q->whereIn('source', (array) $value),
            default => null,
        };
    }

    /** Products bought at the POS (sale_items) or in the online shop (order_items), when those modules are installed. */
    protected function boughtProduct(Builder $q, array $productIds): void
    {
        $sources = [];
        foreach ([['sales', 'sale_items', 'sale_id'], ['orders', 'order_items', 'order_id']] as [$parent, $items, $fk]) {
            if (Schema::hasTable($parent) && Schema::hasTable($items) && Schema::hasColumn($parent, 'client_id') && Schema::hasColumn($items, $fk)) {
                $col = Schema::hasColumn($items, 'product_id') ? 'product_id' : (Schema::hasColumn($items, 'product_variant_id') ? 'product_variant_id' : null);
                if ($col) {
                    $sources[] = [$parent, $items, $fk, $col];
                }
            }
        }
        if (! $sources) {
            $q->whereRaw('1 = 0');

            return;
        }
        $q->where(function ($w) use ($sources, $productIds) {
            foreach ($sources as [$parent, $items, $fk, $col]) {
                $w->orWhereExists(function (QueryBuilder $s) use ($parent, $items, $fk, $col, $productIds) {
                    $s->selectRaw('1')->from($parent)->join($items, "$items.$fk", '=', "$parent.id")->whereColumn("$parent.client_id", 'clients.id');
                    if ($col === 'product_variant_id') {
                        $s->whereIn("$items.product_variant_id", fn (QueryBuilder $v) => $v->select('id')->from('product_variants')->whereIn('product_id', $productIds ?: [0]));
                    } else {
                        $s->whereIn("$items.$col", $productIds ?: [0]);
                    }
                });
            }
        });
    }
}
