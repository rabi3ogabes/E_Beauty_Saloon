<?php

namespace App\Domain\Insights\Ask;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Team\Models\Staff;
use Carbon\CarbonImmutable;

/**
 * Keyword planner used when AI is unavailable: English/Arabic keywords → a whitelist plan. Deliberately simple and
 * predictable; it only ever produces plans that QueryCatalog can validate.
 */
class RulePlanner
{
    protected const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'يناير' => 1, 'فبراير' => 2, 'مارس' => 3, 'أبريل' => 4, 'مايو' => 5, 'يونيو' => 6, 'يوليو' => 7, 'أغسطس' => 8,
        'سبتمبر' => 9, 'أكتوبر' => 10, 'نوفمبر' => 11, 'ديسمبر' => 12,
    ];

    public function plan(string $question, ?CarbonImmutable $now = null): ?array
    {
        $q = mb_strtolower($question);
        $now ??= CarbonImmutable::now(tenant()?->timezone ?? 'UTC');

        $metric = match (true) {
            $this->has($q, ['no-show', 'no show', 'noshow', 'لم يحضر', 'عدم الحضور']) => 'no_show_rate',
            $this->has($q, ['rebook', 'come back', 'return rate', 'إعادة الحجز']) => 'rebook_rate',
            $this->has($q, ['utiliz', 'utilis', 'busy', 'occupancy', 'إشغال', 'الإشغال']) => 'utilization',
            $this->has($q, ['average ticket', 'avg ticket', 'average spend', 'basket', 'متوسط الفاتورة']) => 'average_ticket',
            $this->has($q, ['new client', 'new customer', 'عميلات جدد', 'عملاء جدد']) => 'new_clients',
            $this->has($q, ['top service', 'best service', 'best-selling service', 'أفضل خدمة', 'أكثر الخدمات']) => 'top_services',
            $this->has($q, ['top staff', 'best staff', 'top stylist', 'best stylist', 'أفضل موظفة']) => 'top_staff',
            $this->has($q, ['which branch', 'by branch', 'each branch', 'أي فرع']) => 'revenue_by_branch',
            $this->has($q, ['booking', 'appointment', 'حجز', 'حجوزات', 'مواعيد']) => 'bookings',
            $this->has($q, ['revenue', 'make', 'made', 'earn', 'sales', 'income', 'turnover', 'إيراد', 'دخل', 'مبيعات', 'ربح']) => 'revenue',
            default => null,
        };
        if (! $metric) {
            return null;
        }

        $filters = [];
        $allowed = QueryCatalog::METRICS[$metric]['filters'];
        if (in_array('branch', $allowed, true) && ($b = $this->find(Branch::query()->get(), $q))) {
            $filters['branch'] = (string) $b->name;
        }
        if (in_array('service_category', $allowed, true) && ($c = $this->find(ServiceCategory::query()->get(), $q))) {
            $filters['service_category'] = (string) $c->name;
        }
        if (in_array('staff', $allowed, true) && ($s = $this->find(Staff::query()->get(), $q))) {
            $filters['staff'] = (string) $s->name;
        }

        return ['metric' => $metric, 'filters' => $filters, 'period' => $this->period($q, $now)];
    }

    protected function period(string $q, CarbonImmutable $now): string|array
    {
        foreach (self::MONTHS as $name => $month) {
            if (str_contains($q, $name)) {
                $year = preg_match('/\b(20\d{2})\b/', $q, $m) ? (int) $m[1] : ($month > $now->month ? $now->year - 1 : $now->year);

                return sprintf('%04d-%02d', $year, $month);
            }
        }

        return match (true) {
            $this->has($q, ['last month', 'الشهر الماضي']) => 'last_month',
            $this->has($q, ['this month', 'هذا الشهر']) => 'this_month',
            $this->has($q, ['yesterday', 'أمس']) => 'yesterday',
            $this->has($q, ['today', 'اليوم']) => 'today',
            $this->has($q, ['this week', 'هذا الأسبوع']) => 'this_week',
            $this->has($q, ['last week', 'past week', '7 days', 'الأسبوع الماضي']) => 'last_7',
            $this->has($q, ['this year', 'هذا العام', 'هذه السنة']) => 'this_year',
            $this->has($q, ['90 days', 'quarter', 'ربع']) => 'last_90',
            default => 'last_30',
        };
    }

    protected function has(string $q, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($q, $n)) {
                return true;
            }
        }

        return false;
    }

    /** First model whose (any-locale) name or slug appears in the question; longest name wins. */
    protected function find($models, string $q)
    {
        return $models->map(function ($m) use ($q) {
            $names = array_filter(array_map('mb_strtolower', array_merge(array_values($m->getTranslations('name')), [(string) ($m->slug ?? '')])));
            $hit = collect($names)->filter(fn ($n) => mb_strlen($n) >= 3 && str_contains($q, $n))->sortByDesc(fn ($n) => mb_strlen($n))->first();

            return [$m, $hit ? mb_strlen($hit) : 0];
        })->filter(fn ($r) => $r[1] > 0)->sortByDesc(fn ($r) => $r[1])->first()[0] ?? null;
    }
}
