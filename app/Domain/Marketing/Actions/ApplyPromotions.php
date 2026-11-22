<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Promotion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Prices a cart/booking with the salon's promotions. PUBLIC CONTRACT for Booking, POS and Shop.
 *
 * Pure: it only READS promotions/redemptions and never writes. After the sale is confirmed, persist usage with
 * RecordPromotionRedemption::handle($result['applied'], $reference, $client).
 *
 *   $result = app(ApplyPromotions::class)->handle(
 *       lines: [
 *           ['key' => 'b1', 'type' => 'service', 'id' => 12, 'price' => 15000, 'quantity' => 1],   // price = unit price, minor units
 *           ['key' => 'p1', 'type' => 'product', 'id' => 40, 'price' => 9500,  'quantity' => 2],   // product id (not variant)
 *       ],
 *       client: $client,            // enables first_visit / birthday / personal coupons / per-client limits
 *       couponCode: 'GLOW15',       // optional
 *       at: $booking->starts_at,    // when the service happens (happy hours); default now
 *       branchId: $branch->id,      // branch restrictions + local time for happy hours
 *   );
 *
 *   $result = [
 *       'subtotal' => 34000, 'discount' => 5100, 'total' => 28900,
 *       'lines' => ['b1' => 2250, 'p1' => 2850],                                    // discount per line key
 *       'applied' => [['promotion_id' => 3, 'type' => 'coupon', 'code' => 'GLOW15', 'name' => 'Glow 15', 'amount' => 5100]],
 *       'coupon_error' => null,                                                      // translated message when the code is not usable
 *   ];
 *
 * Stacking: the single best automatic promotion (happy_hour, first_visit, birthday, flash_sale, bogo) plus one coupon
 * (unless the coupon rule "exclusive" is true, in which case the better of the two wins). Never below zero.
 */
class ApplyPromotions
{
    public function handle(array $lines, ?Client $client = null, ?string $couponCode = null, ?CarbonInterface $at = null, ?int $branchId = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $lines = collect($lines)->values()->map(fn ($l, $i) => [
            'key' => (string) ($l['key'] ?? $i), 'type' => $l['type'] ?? 'service', 'id' => (int) ($l['id'] ?? 0),
            'price' => max(0, (int) ($l['price'] ?? 0)), 'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
        ]);
        $subtotal = (int) $lines->sum(fn ($l) => $l['price'] * $l['quantity']);
        $result = ['subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal, 'lines' => $lines->mapWithKeys(fn ($l) => [$l['key'] => 0])->all(), 'applied' => [], 'coupon_error' => null];
        if ($subtotal === 0) {
            return $result;
        }

        $promotions = Promotion::query()->where('is_active', true)->get()->filter(fn (Promotion $p) => $p->isLive($at));

        // Best automatic promotion
        $best = null;
        foreach ($promotions->where('type', '!=', 'coupon') as $promotion) {
            if (! $this->eligible($promotion, $client, $at, $branchId, $subtotal)) {
                continue;
            }
            $calc = $this->calculate($promotion, $lines);
            if ($calc['amount'] > 0 && (! $best || $calc['amount'] > $best['amount'])) {
                $best = $calc + ['promotion' => $promotion];
            }
        }

        // Coupon
        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $promotion = $promotions->first(fn (Promotion $p) => $p->code === strtoupper(trim($couponCode)));
            if (! $promotion || ! $this->eligible($promotion, $client, $at, $branchId, $subtotal)) {
                $result['coupon_error'] = __('marketing.promotions.errors.coupon_invalid');
            } else {
                $calc = $this->calculate($promotion, $lines);
                $calc['amount'] > 0 ? $coupon = $calc + ['promotion' => $promotion] : $result['coupon_error'] = __('marketing.promotions.errors.coupon_not_applicable');
            }
        }

        $applied = array_values(array_filter([$best, $coupon]));
        if ($best && $coupon && $coupon['promotion']->rule('exclusive', false)) {
            $applied = [$coupon['amount'] >= $best['amount'] ? $coupon : $best];
        }

        foreach ($applied as $calc) {
            $remaining = $result['subtotal'] - $result['discount'];
            $amount = min($calc['amount'], $remaining);
            if ($amount <= 0) {
                continue;
            }
            foreach ($calc['lines'] as $key => $lineDiscount) {
                $lineTotal = (int) $lines->firstWhere('key', $key)['price'] * (int) $lines->firstWhere('key', $key)['quantity'];
                $result['lines'][$key] = min($lineTotal, $result['lines'][$key] + $lineDiscount);
            }
            $result['discount'] += $amount;
            $p = $calc['promotion'];
            $result['applied'][] = ['promotion_id' => $p->id, 'type' => $p->type, 'code' => $p->code, 'name' => $p->getTranslation('name', app()->getLocale()), 'amount' => $amount];
        }
        $result['discount'] = min($result['discount'], $subtotal);
        $result['total'] = $subtotal - $result['discount'];

        return $result;
    }

    public function eligible(Promotion $p, ?Client $client, CarbonImmutable $at, ?int $branchId, int $subtotal): bool
    {
        if ($p->branch_ids && $branchId && ! in_array($branchId, array_map('intval', $p->branch_ids), true)) {
            return false;
        }
        if ($subtotal < (int) $p->rule('min_subtotal', 0)) {
            return false;
        }
        if (($personal = $p->rule('client_id')) && (int) $personal !== $client?->id) {
            return false;
        }
        if ($p->per_client_limit && $client && $p->redemptions()->where('client_id', $client->id)->count() >= $p->per_client_limit) {
            return false;
        }
        if ($p->rule('first_visit') && ! $this->isFirstVisit($client)) {
            return false;
        }

        return match ($p->type) {
            'first_visit' => $this->isFirstVisit($client),
            'birthday' => $client?->birthday !== null && $this->inBirthdayWindow($client, $at, (int) $p->rule('days_before', 7), (int) $p->rule('days_after', 7)),
            'happy_hour' => $this->inHappyHour($p, $at, $branchId),
            default => true,
        };
    }

    protected function isFirstVisit(?Client $client): bool
    {
        return $client === null || ((int) $client->visits_count === 0 && $client->last_visit_at === null);
    }

    protected function inBirthdayWindow(Client $client, CarbonImmutable $at, int $before, int $after): bool
    {
        $tz = tenant()?->timezone ?? 'UTC';
        $local = $at->setTimezone($tz)->startOfDay();
        foreach ([-1, 0, 1] as $yearOffset) {
            $first = CarbonImmutable::create($local->year + $yearOffset, $client->birthday->month, 1, 0, 0, 0, $tz);
            $bday = $first->day(min($client->birthday->day, $first->daysInMonth));
            if ($local->between($bday->subDays($before), $bday->addDays($after))) {
                return true;
            }
        }

        return false;
    }

    protected function inHappyHour(Promotion $p, CarbonImmutable $at, ?int $branchId): bool
    {
        $tz = ($branchId ? Branch::withoutGlobalScopes()->whereKey($branchId)->value('timezone') : null) ?: (tenant()?->timezone ?? 'UTC');
        $local = $at->setTimezone($tz);
        $weekdays = array_map('intval', (array) $p->rule('weekdays', [0, 1, 2, 3, 4, 5, 6]));
        if (! in_array($local->dayOfWeek, $weekdays, true)) {
            return false;
        }
        $time = $local->format('H:i');

        return $time >= (string) $p->rule('from', '00:00') && $time < (string) $p->rule('to', '23:59');
    }

    /** @return array{amount:int, lines: array<string,int>} */
    public function calculate(Promotion $p, $lines): array
    {
        $appliesTo = $p->rule('applies_to', 'all');
        $serviceIds = array_map('intval', (array) $p->rule('service_ids', []));
        $productIds = array_map('intval', (array) $p->rule('product_ids', []));
        $eligible = $lines->filter(function ($l) use ($appliesTo, $serviceIds, $productIds) {
            if ($appliesTo === 'services' && $l['type'] !== 'service') {
                return false;
            }
            if ($appliesTo === 'products' && $l['type'] !== 'product') {
                return false;
            }
            if ($l['type'] === 'service' && $serviceIds) {
                return in_array($l['id'], $serviceIds, true);
            }
            if ($l['type'] === 'product' && $productIds) {
                return in_array($l['id'], $productIds, true);
            }

            return true;
        });
        $out = [];

        if ($p->type === 'bogo') {
            $buy = max(1, (int) $p->rule('buy_quantity', 1));
            $get = max(1, (int) $p->rule('get_quantity', 1));
            $bp = (int) $p->rule('get_discount_bp', 10000);
            $units = $eligible->flatMap(fn ($l) => array_fill(0, $l['quantity'], ['key' => $l['key'], 'price' => $l['price']]))->sortByDesc('price')->values();
            foreach ($units->chunk($buy + $get) as $group) {
                if ($group->count() < $buy + $get) {
                    break;
                }
                foreach ($group->slice($buy) as $unit) {
                    $out[$unit['key']] = ($out[$unit['key']] ?? 0) + intdiv($unit['price'] * $bp, 10000);
                }
            }

            return ['amount' => array_sum($out), 'lines' => $out];
        }

        $eligibleTotal = (int) $eligible->sum(fn ($l) => $l['price'] * $l['quantity']);
        if ($eligibleTotal === 0) {
            return ['amount' => 0, 'lines' => []];
        }

        if ($p->discount_type === 'percent') {
            foreach ($eligible as $l) {
                $out[$l['key']] = intdiv($l['price'] * $l['quantity'] * (int) $p->discount_value, 10000);
            }
            $amount = array_sum($out);
            if ($cap = (int) $p->rule('max_discount', 0)) {
                $amount = min($amount, $cap);
            }

            return ['amount' => $amount, 'lines' => $out];
        }

        // Fixed amount spread proportionally over the eligible lines.
        $amount = min((int) $p->discount_value, $eligibleTotal);
        $allocated = 0;
        foreach ($eligible->values() as $i => $l) {
            $share = $i === $eligible->count() - 1 ? $amount - $allocated : intdiv($amount * $l['price'] * $l['quantity'], $eligibleTotal);
            $out[$l['key']] = $share;
            $allocated += $share;
        }

        return ['amount' => $amount, 'lines' => $out];
    }
}
