<?php

require_once __DIR__.'/PosTestHelpers.php';

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Loyalty\Actions\LoyaltyLedger;
use App\Domain\Payments\Actions\IssueGiftCard;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\Payments\Models\GiftCard;
use App\Domain\Payments\Models\Payment;
use App\Domain\POS\Actions\BuildShiftSummary;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Support\BookingTicket;
use App\Domain\POS\Support\PosApprovals;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

// Production-like strictness: catch N+1 / lazy loading in POS code paths.
beforeEach(fn () => Model::preventLazyLoading());
afterEach(fn () => Model::preventLazyLoading(false));

/*
 * Acceptance: "Checkout of 3 services + 2 products with split payment in under 30 seconds".
 * The < 30 s target is a UX property of the terminal (tap tiles → tap staff → Cash/Card quick tender → Complete,
 * no page loads, barcode scan + Enter adds products); here we prove the checkout itself is correct and atomic.
 */
it('checks out 3 services and 2 products with a split card + cash payment', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);
    [$blowDry, $gel, $brows] = $s->services->all();
    [$oil, $shampoo] = $s->products->all();

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(),
        'branch_id' => $s->branch->id, 'register_id' => $s->register->id, 'shift_id' => $s->shift->id, 'client_id' => $s->client->id,
        'lines' => [
            ['type' => 'service', 'id' => $blowDry->id, 'staff_id' => $s->layla->id],
            ['type' => 'service', 'id' => $gel->id, 'staff_id' => $s->mariam->id],
            ['type' => 'service', 'id' => $brows->id, 'staff_id' => $s->mariam->id],
            ['type' => 'product', 'id' => $oil->id, 'qty' => 1, 'staff_id' => $s->layla->id],
            ['type' => 'product', 'id' => $shampoo->id, 'qty' => 1, 'staff_id' => $s->layla->id],
        ],
        'tips' => [$s->layla->id => 2000],
        'payments' => [
            ['method' => 'card_terminal', 'amount' => 30000, 'reference' => 'TRX-1'],
            ['method' => 'cash', 'tendered' => 40000],
        ],
    ], $s->owner);

    // Totals (5% VAT included in prices)
    expect($sale->status)->toBe(SaleStatus::Completed)
        ->and($sale->subtotal)->toBe(60500)
        ->and($sale->discount)->toBe(0)
        ->and($sale->tax)->toBe(714 + 857 + 286 + 452 + 571)
        ->and($sale->tips)->toBe(2000)
        ->and($sale->total)->toBe(62500)
        ->and($sale->paid)->toBe(62500)
        ->and($sale->change_due)->toBe(7500)
        ->and($sale->number)->toStartWith('M-')
        ->and($sale->items)->toHaveCount(5);

    // Payments: core payments rows with payable = Sale
    $payments = Payment::where('payable_type', $sale->getMorphClass())->where('payable_id', $sale->id)->get();
    expect($payments->pluck('amount', 'method')->all())->toBe(['card_terminal' => 30000, 'cash' => 32500])
        ->and($payments->firstWhere('method', 'card_terminal')->provider_reference)->toBe('TRX-1')
        ->and($sale->salePayments->firstWhere('method', 'cash')->tendered)->toBe(40000);

    // Stock
    expect(variantStock($oil, $s->branch->id))->toBe(9)->and(variantStock($shampoo, $s->branch->id))->toBe(9);

    // Commissions: Layla staff rule 20% on services; defaults 10% services / 5% products (on net excl. VAT)
    $byStaff = Commission::all()->groupBy('staff_id')->map->sum('amount');
    expect($byStaff[$s->layla->id])->toBe(2857 + 452 + 571)
        ->and($byStaff[$s->mariam->id])->toBe(1714 + 571);

    // Loyalty (1 point per QAR, tips excluded) + client stats
    $client = $s->client->fresh();
    expect($client->loyalty_points)->toBe(605)
        ->and($client->visits_count)->toBe(1)
        ->and($client->lifetime_value)->toBe(60500)
        ->and($client->last_visit_at)->not->toBeNull();

    // Drawer: float + cash applied (change excluded)
    expect(app(BuildShiftSummary::class)->handle($s->shift)['expected_cash'])->toBe(20000 + 32500);

    expect(Activity::where('description', 'pos.sale.completed')->count())->toBe(1);
});

it('applies exclusive tax on top of prices', function () {
    $s = posSalon(['tax_percent' => 5, 'tax_inclusive' => false]);
    posActingAs($s->tenant, $s->owner);

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id]],
        'payments' => [['method' => 'cash', 'tendered' => 15750]],
    ], $s->owner);

    expect($sale->tax)->toBe(750)->and($sale->total)->toBe(15750)->and($sale->change_due)->toBe(0);
});

it('refuses an under-paid ticket and leaves nothing behind', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);

    expect(fn () => app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
        'lines' => [['type' => 'product', 'id' => $s->products[0]->id, 'qty' => 2]],
        'payments' => [['method' => 'cash', 'tendered' => 1000]],
    ], $s->owner))->toThrow(DomainException::class);

    expect(Sale::count())->toBe(0)->and(variantStock($s->products[0], $s->branch->id))->toBe(10);
});

it('pays with gift card, wallet and loyalty points together', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $card = app(IssueGiftCard::class)->handle(5000);
    app(WalletLedger::class)->credit($s->client, 4000);
    app(LoyaltyLedger::class)->award($s->client, 1200); // 1200 × 0.05 = 60.00

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'client_id' => $s->client->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id]], // 150.00
        'payments' => [
            ['method' => 'gift_card', 'code' => strtolower($card->code), 'amount' => 5000],
            ['method' => 'wallet', 'amount' => 4000],
            ['method' => 'points', 'points' => 1200],
        ],
    ], $s->owner);

    expect($sale->paid)->toBe(15000)
        ->and($card->fresh()->balance)->toBe(0)
        ->and($s->client->fresh()->wallet_balance)->toBe(0)
        ->and($sale->salePayments->pluck('method')->all())->toBe(['gift_card', 'wallet', 'points']);
});

it('sells a gift card and issues it', function () {
    $s = posSalon();
    posActingAs($s->tenant, $s->owner);

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'client_id' => $s->client->id,
        'lines' => [['type' => 'gift_card', 'unit_price' => 50000, 'recipient' => ['name' => 'Sara']]],
        'payments' => [['method' => 'card_terminal', 'amount' => 50000]],
    ], $s->owner);

    $card = GiftCard::firstOrFail();
    expect($sale->tax)->toBe(0)
        ->and($card->balance)->toBe(50000)->and($card->recipient_name)->toBe('Sara')
        ->and($sale->items[0]->sellable_id)->toBe($card->id);
});

it('shows a booking deposit paid online as paid on the ticket and completes the booking', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $service = $s->services[0];

    $booking = Booking::create([
        'branch_id' => $s->branch->id, 'client_id' => $s->client->id, 'status' => BookingStatus::CheckedIn,
        'starts_at' => now()->subHour(), 'ends_at' => now(), 'subtotal' => 15000, 'total' => 15000, 'amount_paid' => 5000, 'deposit_required' => 5000,
    ]);
    $item = $booking->items()->create(['service_id' => $service->id, 'staff_id' => $s->layla->id, 'starts_at' => now()->subHour(), 'ends_at' => now(), 'price' => 15000, 'duration_minutes' => 45]);
    $deposit = Payment::create(['payable_type' => $booking->getMorphClass(), 'payable_id' => $booking->id, 'client_id' => $s->client->id, 'method' => 'online', 'driver' => 'sadad', 'purpose' => 'deposit', 'status' => 'succeeded', 'amount' => 5000, 'paid_at' => now()]);

    // The terminal loads it as a ticket with the deposit as a paid line
    $ticket = BookingTicket::from($booking->fresh());
    expect($ticket['deposit'])->toBe(5000)
        ->and($ticket['lines'][0])->toMatchArray(['type' => 'service', 'id' => $service->id, 'staff_id' => $s->layla->id, 'booking_item_id' => $item->id]);

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'booking_id' => $booking->id,
        'lines' => $ticket['lines'],
        'payments' => [['method' => 'cash', 'tendered' => 10000]],
    ], $s->owner);

    $booking->refresh();
    expect($sale->paid)->toBe(15000)
        ->and($sale->salePayments->firstWhere('method', 'deposit')->payment_id)->toBe($deposit->id)
        ->and($sale->salePayments->firstWhere('method', 'deposit')->amount)->toBe(5000)
        ->and($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->sale_id)->toBe($sale->id)
        ->and($booking->history()->first()->to_status)->toBe(BookingStatus::Completed);
});

it('requires pos.discount or a manager approval for discounts', function () {
    $s = posSalon(['tax_percent' => 0]);
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $manager = tenantUser($s->tenant, 'branch_manager', [$s->branch->id]);
    posActingAs($s->tenant, $receptionist);

    $payload = fn (array $extra = []) => array_merge([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id, 'discount_type' => 'percent', 'discount_value' => 1000]],
        'payments' => [['method' => 'cash', 'tendered' => 13500]],
    ], $extra);

    expect(fn () => app(CompleteSale::class)->handle($payload(), $receptionist))->toThrow(DomainException::class, __('pos.errors.discount_needs_approval'));

    $sale = app(CompleteSale::class)->handle($payload(['discount_approval' => PosApprovals::issue($manager, $receptionist)]), $receptionist);
    expect($sale->discount)->toBe(1500)->and($sale->discount_meta['approved_by'])->toBe($manager->id);
});

it('rejects a client-supplied approver id and single-use approval tokens are not reusable', function () {
    $s = posSalon(['tax_percent' => 0]);
    $receptionist = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    posActingAs($s->tenant, $receptionist);
    $payload = fn (array $extra = []) => array_merge([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id,
        'lines' => [['type' => 'service', 'id' => $s->services[0]->id, 'discount_type' => 'percent', 'discount_value' => 10000]],
        'payments' => [['method' => 'cash', 'tendered' => 0]],
    ], $extra);

    // Old forgeable field is ignored.
    expect(fn () => app(CompleteSale::class)->handle($payload(['discount_approved_by' => $s->owner->id]), $receptionist))
        ->toThrow(DomainException::class, __('pos.errors.discount_needs_approval'));
    // A token issued to another cashier doesn't work.
    $other = tenantUser($s->tenant, 'receptionist', [$s->branch->id]);
    $foreign = PosApprovals::issue($s->owner, $other);
    expect(fn () => app(CompleteSale::class)->handle($payload(['discount_approval' => $foreign]), $receptionist))->toThrow(DomainException::class);
    // A valid token works exactly once.
    $token = PosApprovals::issue($s->owner, $receptionist);
    app(CompleteSale::class)->handle($payload(['discount_approval' => $token]), $receptionist);
    expect(fn () => app(CompleteSale::class)->handle($payload(['discount_approval' => $token]), $receptionist))->toThrow(DomainException::class);
});
