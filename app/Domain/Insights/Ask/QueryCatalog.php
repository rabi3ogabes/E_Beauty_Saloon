<?php

namespace App\Domain\Insights\Ask;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\InsightsGate;
use App\Domain\Insights\Support\Period;
use App\Domain\Team\Models\Staff;
use Illuminate\Database\Eloquent\Model;

/**
 * The whitelist for "Ask your data": the ONLY queries a question can run. A plan is
 *   {"metric": "<name>", "filters": {"branch": "...", "service_category": "...", ...}, "period": "last_month" | "2026-10" | {"from": "...", "to": "..."}}
 * Metrics map to read-only, tenant-scoped ReportService methods; filter values are names resolved to ids through
 * the tenant's own (scoped) catalog. Anything else — unknown metric, unknown key, unresolvable value, SQL — is
 * rejected. The AI never writes SQL and never sees query results it did not ask for.
 */
class QueryCatalog
{
    public const METRICS = [
        'revenue' => ['filters' => ['branch', 'service_category', 'service', 'staff', 'product', 'channel'], 'money' => true],
        'bookings' => ['filters' => ['branch', 'service_category', 'service', 'staff'], 'money' => false],
        'average_ticket' => ['filters' => ['branch'], 'money' => true],
        'no_show_rate' => ['filters' => ['branch', 'staff'], 'money' => false],
        'rebook_rate' => ['filters' => ['branch', 'staff'], 'money' => false],
        'utilization' => ['filters' => ['branch'], 'money' => false],
        'new_clients' => ['filters' => [], 'money' => false],
        'top_services' => ['filters' => ['branch', 'service_category'], 'money' => true],
        'top_staff' => ['filters' => ['branch'], 'money' => true],
        'revenue_by_branch' => ['filters' => [], 'money' => true],
    ];

    public const PERIODS = ['today', 'yesterday', 'last_7', 'last_28', 'last_30', 'last_90', 'this_week', 'this_month', 'last_month', 'this_year'];

    public function __construct(protected ReportService $reports) {}

    /**
     * Validate a plan and resolve its filters. Returns [metric, filters (ids), period, labels].
     *
     * @throws RejectedPlan
     */
    public function validate(mixed $plan): array
    {
        if (! is_array($plan) || array_diff(array_keys($plan), ['metric', 'filters', 'period'])) {
            throw new RejectedPlan(__('insights.ask.rejected.shape'));
        }
        $metric = $plan['metric'] ?? null;
        if (! is_string($metric) || ! isset(self::METRICS[$metric])) {
            throw new RejectedPlan(__('insights.ask.rejected.metric', ['metric' => is_string($metric) ? mb_substr($metric, 0, 40) : '?']));
        }
        if (self::METRICS[$metric]['money'] && ! InsightsGate::revenue()) {
            throw new RejectedPlan(__('insights.ask.rejected.permission'));
        }
        $filters = $plan['filters'] ?? [];
        if (! is_array($filters)) {
            throw new RejectedPlan(__('insights.ask.rejected.shape'));
        }
        $resolved = [];
        $labels = [];
        foreach ($filters as $key => $value) {
            if (! in_array($key, self::METRICS[$metric]['filters'], true)) {
                throw new RejectedPlan(__('insights.ask.rejected.filter', ['filter' => mb_substr((string) $key, 0, 40)]));
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_scalar($value) || mb_strlen((string) $value) > 100) {
                throw new RejectedPlan(__('insights.ask.rejected.shape'));
            }
            [$column, $id, $label] = $this->resolve($key, (string) $value);
            $resolved[$column] = $id;
            $labels[$key] = $label;
        }

        return [$metric, $resolved, $this->period($plan['period'] ?? 'last_30'), $labels];
    }

    /** @return array{0:string, 1:int|string, 2:string} [ReportService filter, value, display label] */
    protected function resolve(string $key, string $value): array
    {
        if ($key === 'channel') {
            $v = strtolower($value);
            if (! in_array($v, ['pos', 'shop'], true)) {
                throw new RejectedPlan(__('insights.ask.rejected.value', ['value' => $value]));
            }

            return ['channel', $v, __('insights.ask.channels.'.$v)];
        }
        [$class, $column] = match ($key) {
            'branch' => [Branch::class, 'branch_id'],
            'service_category' => [ServiceCategory::class, 'service_category_id'],
            'service' => [Service::class, 'service_id'],
            'staff' => [Staff::class, 'staff_id'],
            'product' => [Product::class, 'product_id'],
        };
        $model = $this->match($class, $value);
        if (! $model) {
            throw new RejectedPlan(__('insights.ask.rejected.value', ['value' => $value]));
        }

        return [$column, (int) $model->getKey(), (string) $model->name];
    }

    /** Case-insensitive name match (any locale) or slug, within the tenant's own scoped rows. */
    protected function match(string $class, string $value): ?Model
    {
        $needle = mb_strtolower(trim($value));
        $rows = $class::query()->get();
        $score = function (Model $m) use ($needle) {
            $names = array_map('mb_strtolower', array_filter(array_merge(
                method_exists($m, 'getTranslations') ? array_values($m->getTranslations('name')) : [(string) $m->name],
                [(string) ($m->slug ?? '')],
            )));
            foreach ($names as $n) {
                if ($n === $needle) {
                    return 3;
                }
            }
            foreach ($names as $n) {
                if ($n !== '' && (str_contains($n, $needle) || str_contains($needle, $n))) {
                    return 2;
                }
            }

            return 0;
        };

        return $rows->map(fn ($m) => [$m, $score($m)])->filter(fn ($r) => $r[1] > 0)->sortByDesc(fn ($r) => $r[1])->first()[0] ?? null;
    }

    public function period(mixed $period): Period
    {
        if (is_string($period) && (in_array($period, self::PERIODS, true) || preg_match('/^\d{4}-\d{2}$/', $period))) {
            return Period::preset($period);
        }
        if (is_array($period) && isset($period['from'], $period['to']) && is_string($period['from']) && is_string($period['to'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period['to'])) {
            return Period::between($period['from'], $period['to']);
        }

        throw new RejectedPlan(__('insights.ask.rejected.period'));
    }

    /** Run a validated query. @return array{value: mixed, rows: list<array{label:string, value:string}>} */
    public function run(string $metric, array $filters, Period $period): array
    {
        $r = $this->reports;

        return match ($metric) {
            'revenue' => ['value' => $v = $r->revenueTotal($period, $filters), 'rows' => [[__('insights.ask.metrics.revenue'), money($v)]]],
            'bookings' => ['value' => $v = $r->bookingsCount($period, $filters), 'rows' => [[__('insights.ask.metrics.bookings'), (string) $v]]],
            'average_ticket' => (function () use ($r, $period, $filters) {
                $t = $r->averageTicket($period, $filters);

                return ['value' => $t['average'], 'rows' => [[__('insights.ask.metrics.average_ticket'), money($t['average'])], [__('insights.reports.cols.tickets'), (string) $t['tickets']]]];
            })(),
            'no_show_rate' => (function () use ($r, $period, $filters) {
                $n = $r->noShowRate($period, $filters);

                return ['value' => $n['rate'], 'rows' => [[__('insights.ask.metrics.no_show_rate'), $n['rate'].'%'], [__('insights.reports.cols.no_shows'), (string) $n['no_shows']], [__('insights.reports.cols.visits'), (string) $n['completed']]]];
            })(),
            'rebook_rate' => (function () use ($r, $period, $filters) {
                $n = $r->rebookRate($period, $filters);

                return ['value' => $n['rate'], 'rows' => [[__('insights.ask.metrics.rebook_rate'), $n['rate'].'%'], [__('insights.reports.cols.visits'), (string) $n['visits']]]];
            })(),
            'utilization' => (function () use ($r, $period, $filters) {
                $u = $r->utilization($period, $filters);

                return ['value' => $u['rate'], 'rows' => [[__('insights.ask.metrics.utilization'), $u['rate'].'%']]];
            })(),
            'new_clients' => ['value' => $v = $r->newClients($period), 'rows' => [[__('insights.ask.metrics.new_clients'), (string) $v]]],
            'top_services' => (function () use ($r, $period, $filters) {
                $rows = array_slice($r->revenueByService($period, $filters), 0, 5);

                return ['value' => $rows[0]['service'] ?? null, 'rows' => array_map(fn ($s) => [$s['service'], money($s['total']).' · '.$s['count']], $rows)];
            })(),
            'top_staff' => (function () use ($r, $period, $filters) {
                $rows = array_slice($r->revenueByStaff($period, $filters), 0, 5);

                return ['value' => $rows[0]['staff'] ?? null, 'rows' => array_map(fn ($s) => [$s['staff'], money($s['total'])], $rows)];
            })(),
            'revenue_by_branch' => (function () use ($r, $period) {
                $rows = $r->revenueByBranch($period);

                return ['value' => $rows[0]['branch'] ?? null, 'rows' => array_map(fn ($b) => [$b['branch'], money($b['total'])], $rows)];
            })(),
        };
    }

    /** Short catalog description for the AI planner (names only — no client data). */
    public function describe(): array
    {
        return [
            'metrics' => array_map(fn ($m) => $m['filters'], self::METRICS),
            'periods' => array_merge(self::PERIODS, ['YYYY-MM', '{"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}']),
            'branches' => Branch::query()->get()->map(fn ($b) => (string) $b->name)->all(),
            'service_categories' => ServiceCategory::query()->get()->map(fn ($c) => (string) $c->name)->all(),
            'channels' => ['pos', 'shop'],
        ];
    }
}
