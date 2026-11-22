<?php

require_once __DIR__.'/../Pos/PosTestHelpers.php';

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Package;
use App\Domain\POS\Actions\CompleteSale;
use App\Domain\POS\Actions\RefundSale;
use App\Domain\POS\Support\BookingTicket;
use App\Domain\POS\Support\SellableRegistry;

it('earns loyalty once when a booking is checked out at the POS, and reverses it on refund', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $service = $s->services[0];

    $booking = Booking::create([
        'branch_id' => $s->branch->id, 'client_id' => $s->client->id, 'status' => BookingStatus::CheckedIn,
        'starts_at' => now()->subHour(), 'ends_at' => now(), 'subtotal' => 15000, 'total' => 15000,
    ]);
    $booking->items()->create(['service_id' => $service->id, 'staff_id' => $s->layla->id, 'starts_at' => now()->subHour(), 'ends_at' => now(), 'price' => 15000, 'duration_minutes' => 45]);
    $before = $s->client->fresh()->loyalty_points;

    $sale = app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'booking_id' => $booking->id,
        'client_id' => $s->client->id, 'lines' => BookingTicket::from($booking->fresh())['lines'],
        'payments' => [['method' => 'cash', 'tendered' => 15000]],
    ], $s->owner);

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed)
        ->and(LoyaltyEntry::where('client_id', $s->client->id)->where('points', '>', 0)->where('type', 'earn')->count())->toBe(1)
        ->and($s->client->fresh()->loyalty_points - $before)->toBe(150);

    app(RefundSale::class)->handle($sale, null, false, 'Unhappy', $s->owner, $s->owner);
    expect($s->client->fresh()->loyalty_points)->toBe($before)
        ->and(LoyaltyEntry::where('type', 'reversal')->count())->toBe(1);
});

it('sells a package at the POS and gives the client their sessions', function () {
    $s = posSalon(['tax_percent' => 0]);
    posActingAs($s->tenant, $s->owner);
    $package = Package::create([
        'name' => ['en' => '6 Blowouts', 'ar' => '6 استشوار'], 'slug' => 'six-blowouts', 'pricing' => 'fixed', 'price' => 60000, 'sessions_count' => 6,
    ]);
    $package->items()->create(['itemable_type' => $s->services[0]->getMorphClass(), 'itemable_id' => $s->services[0]->id, 'quantity' => 6]);

    expect(collect(app(SellableRegistry::class)->catalog('package', $s->branch))->pluck('id'))->toContain($package->id);

    app(CompleteSale::class)->handle([
        'idempotency_key' => saleKey(), 'branch_id' => $s->branch->id, 'client_id' => $s->client->id,
        'lines' => [['type' => 'package', 'id' => $package->id]],
        'payments' => [['method' => 'cash', 'tendered' => 60000]],
    ], $s->owner);

    $cp = ClientPackage::where('client_id', $s->client->id)->first();
    expect($cp)->not->toBeNull()->and($cp->status)->toBe('active')->and($cp->sessions_remaining)->toBe(6)->and($cp->channel)->toBe('pos');
});
