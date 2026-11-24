<?php

namespace Database\Seeders\Demo;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\POS\Actions\BuildShiftSummary;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\RefundSale;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Shift;
use App\Domain\POS\Support\PosApprovals;
use App\Domain\POS\Support\TicketCalculator;
use App\Domain\Team\Models\CommissionRule;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * POS demo for "E Beauty Saloon": a register per branch, commission rules, ~150 completed sales over the
 * last ~60 days (split payments, tips, a few discounts and refunds) with closed daily shifts, and today's
 * shift left open so the terminal is ready. Independent of the booking demo seeder.
 */
class PosDemoSeeder extends Seeder
{
    public static int $order = 30;

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            if (Register::query()->exists()) {
                return; // already seeded
            }
            mt_srand(4242);
            $this->seed($tenant);
        });
    }

    protected function seed(Tenant $tenant): void
    {
        $branches = Branch::query()->orderBy('sort')->get();
        $owner = $tenant->owner;
        $manager = User::where('tenant_id', $tenant->id)->where('email', 'manager@lumiere.test')->first() ?? $owner;
        $reception = User::where('tenant_id', $tenant->id)->where('email', 'reception@lumiere.test')->first() ?? $owner;

        $this->seedCommissionRules();

        $registers = $branches->mapWithKeys(fn (Branch $b, $i) => [$b->id => Register::create([
            'branch_id' => $b->id, 'name' => $i === 0 ? 'Front desk' : 'Reception', 'code' => strtoupper(Str::substr($b->slug, 0, 2)), 'default_float' => 50000,
        ])]);

        $services = Service::query()->active()->with('branches')->get();
        $variants = ProductVariant::query()->with('product')->get();
        $clients = Client::query()->inRandomOrder()->limit(120)->get();
        $staffByBranch = $branches->mapWithKeys(fn (Branch $b) => [$b->id => Staff::query()->with('services')
            ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $b->id))->get()]);

        $complete = app(CompleteSale::class);
        $tz = $branches->first()?->timezone ?? 'Asia/Qatar';
        $salesTarget = 150;
        $made = 0;
        $skipped = 0;

        // Newest first, so today and this week always look alive; older days fill up to the target.
        // The window is generous on purpose: ~3 sales/day reach the target in ~50-60 days, and a hard 60-day cap
        // made the count fall one short on some dates (fewer Fridays, a skipped ticket).
        for ($daysAgo = 0; $daysAgo <= 120 && $made < $salesTarget; $daysAgo++) {
            $day = CarbonImmutable::now($tz)->subDays($daysAgo)->startOfDay();
            foreach ($branches as $bi => $branch) {
                $register = $registers[$branch->id];
                $cashier = $bi === 0 ? ($daysAgo % 2 ? $reception : $manager) : $owner;
                $opened = $day->setTime(9, 45);
                $shift = Shift::create([
                    'branch_id' => $branch->id, 'pos_register_id' => $register->id, 'user_id' => $cashier->id,
                    'status' => 'open', 'opening_float' => 50000, 'opened_at' => $opened->utc(),
                ]);

                $perDay = $daysAgo === 0 ? 2 : mt_rand($bi === 0 ? 1 : 0, 2) + ($day->isFriday() ? 1 : 0);
                for ($n = 0; $n < $perDay && $made < $salesTarget; $n++) {
                    $at = $day->setTime(mt_rand(10, 20), mt_rand(0, 59));
                    if ($at->isFuture()) {
                        $at = CarbonImmutable::now($tz)->subMinutes(mt_rand(5, 90));
                    }
                    $payload = $this->ticket($branch, $staffByBranch[$branch->id], $services, $variants, $clients, $shift, $register, $at);
                    if (! $payload) {
                        continue;
                    }
                    if (! empty($payload['ticket_discount']) && ! $cashier->can('pos.discount')) {
                        $payload['discount_approval'] = PosApprovals::issue($manager, $cashier);
                    }
                    try {
                        $sale = $complete->handle($payload, $cashier);
                        $made++;
                        // ~8% of sales come back (partial refund to store credit or card)
                        if ($sale->client_id && mt_rand(1, 12) === 1) {
                            $item = $sale->items->first();
                            app(RefundSale::class)->handle($sale, [$item->id => 1], (bool) mt_rand(0, 1), 'Client feedback', $cashier, $manager, 'refund', $daysAgo === 0 ? $shift : null);
                        }
                    } catch (\Throwable $e) {
                        $skipped++; // a ticket the demo data cannot satisfy (e.g. stock just ran out)
                    }
                }

                if ($daysAgo > 0) {
                    $summary = app(BuildShiftSummary::class)->handle($shift);
                    $variance = [0, 0, 0, -500, 1000, -2000][mt_rand(0, 5)];
                    $shift->forceFill([
                        'status' => 'closed', 'closed_by' => $cashier->id, 'closed_at' => $day->setTime(22, 10)->utc(),
                        'expected_cash' => $summary['expected_cash'], 'counted_cash' => max(0, $summary['expected_cash'] + $variance),
                        'variance' => $variance, 'summary' => $summary,
                    ])->save();
                }
            }
        }

        $this->command?->info("  POS demo: {$made} sales, {$skipped} skipped.");
    }

    /** One realistic ticket: 1–3 services by the branch team, sometimes retail, split payments, tips. */
    protected function ticket(Branch $branch, Collection $staff, Collection $services, Collection $variants, Collection $clients, Shift $shift, Register $register, CarbonImmutable $at): ?array
    {
        if ($staff->isEmpty()) {
            return null;
        }
        $client = mt_rand(1, 10) <= 8 ? $clients->random() : null;
        $lines = [];
        $tips = [];

        foreach (range(1, mt_rand(1, 3)) as $_) {
            $stylist = $staff->random();
            $service = $stylist->services->filter(fn ($s) => $services->contains('id', $s->id))->shuffle()->first();
            if (! $service) {
                continue;
            }
            $lines[] = ['type' => 'service', 'id' => $service->id, 'staff_id' => $stylist->id];
            if (mt_rand(1, 4) === 1) {
                $tips[$stylist->id] = ($tips[$stylist->id] ?? 0) + [2000, 3000, 5000][mt_rand(0, 2)];
            }
        }
        if (mt_rand(1, 10) <= 4) {
            foreach ($variants->shuffle()->take(mt_rand(1, 2)) as $variant) {
                if ($variant->availableAt($branch->id) >= 2) {
                    $lines[] = ['type' => 'product', 'id' => $variant->id, 'qty' => 1, 'staff_id' => $staff->random()->id];
                }
            }
        }
        if (! $lines) {
            return null;
        }

        $discount = mt_rand(1, 12) === 1 ? ['type' => 'percent', 'value' => 1000] : null;
        $estimate = $this->estimate($lines, $branch, $services, $variants, $discount) + array_sum($tips);

        // Split payments: card, cash (with change), card + cash, or wallet top-up first.
        $style = mt_rand(1, 10);
        $payments = match (true) {
            $style <= 4 => [['method' => 'card_terminal', 'amount' => $estimate, 'reference' => 'TRM-'.mt_rand(100000, 999999)]],
            $style <= 7 => [['method' => 'cash', 'tendered' => (int) (ceil($estimate / 5000) * 5000)]],
            default => [
                ['method' => 'card_terminal', 'amount' => intdiv($estimate, 2), 'reference' => 'TRM-'.mt_rand(100000, 999999)],
                ['method' => 'cash', 'tendered' => (int) (ceil(($estimate - intdiv($estimate, 2)) / 1000) * 1000)],
            ],
        };

        // Loyal clients sometimes pay part with points.
        if ($client && mt_rand(1, 6) === 1) {
            $loyalty = app(LoyaltyLedger::class);
            $points = min((int) Client::whereKey($client->id)->value('loyalty_points'), intdiv($estimate, max(1, $loyalty->valueOf(1))));
            if ($points >= 100) {
                $payments = [['method' => 'points', 'points' => $points], ['method' => 'card_terminal', 'amount' => $estimate - $loyalty->valueOf($points), 'reference' => 'TRM-'.mt_rand(100000, 999999)]];
            }
        }

        return [
            'idempotency_key' => (string) Str::ulid(),
            'branch_id' => $branch->id, 'register_id' => $register->id, 'shift_id' => $shift->id,
            'client_id' => $client?->id,
            'lines' => $lines,
            'ticket_discount' => $discount,
            'tips' => $tips,
            'payments' => $payments,
            'offline_created_at' => $at->toIso8601String(), // back-dated completion time
        ];
    }

    /** Same maths CompleteSale will do, so the tender matches the server total. */
    protected function estimate(array $lines, Branch $branch, Collection $services, Collection $variants, ?array $discount): int
    {
        $priced = array_map(function ($l) use ($branch, $services, $variants) {
            if ($l['type'] === 'service') {
                $staff = Staff::withoutGlobalScopes()->with('services')->find($l['staff_id']);

                return $l + ['unit_price' => $services->firstWhere('id', $l['id'])->priceAt($branch, $staff), 'tax_percent' => null];
            }
            $variant = $variants->firstWhere('id', $l['id']);

            return $l + ['unit_price' => $variant->effectivePrice(), 'tax_percent' => $variant->product?->tax_percent];
        }, $lines);

        return TicketCalculator::calculate($priced, $discount, [], (float) $branch->tax_percent, (bool) $branch->tax_inclusive)['total'];
    }

    protected function seedCommissionRules(): void
    {
        if (CommissionRule::query()->exists()) {
            return;
        }
        CommissionRule::create(['applies_to' => 'service', 'type' => 'percent', 'value' => 1000]);   // 10% services
        CommissionRule::create(['applies_to' => 'product', 'type' => 'percent', 'value' => 500]);    // 5% retail
        CommissionRule::create(['applies_to' => 'gift_card', 'type' => 'percent', 'value' => 0]);
        if ($director = Staff::query()->where('color', '#C8A96A')->first()) {
            // Creative director: tiered — 15% up to QAR 20,000 per month, then 20%
            CommissionRule::create(['staff_id' => $director->id, 'applies_to' => 'service', 'type' => 'percent', 'value' => 1500,
                'tiers' => [['from' => 0, 'value' => 1500], ['from' => 2000000, 'value' => 2000]]]);
        }
    }
}
