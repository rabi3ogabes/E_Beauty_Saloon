<?php

require_once __DIR__.'/PosTestHelpers.php';

use App\Domain\Payments\Models\Payment;
use App\Domain\POS\Models\Sale;
use Illuminate\Database\Eloquent\Model;

// Production-like strictness: catch N+1 / lazy loading in POS code paths.
beforeEach(fn () => Model::preventLazyLoading());
afterEach(fn () => Model::preventLazyLoading(false));

function offlineSale(object $s, array $override = []): array
{
    return array_merge([
        'idempotency_key' => saleKey(),
        'branch_id' => $s->branch->id, 'register_id' => $s->register->id, 'shift_id' => $s->shift->id,
        'lines' => [['type' => 'product', 'id' => $s->products[0]->id, 'qty' => 2]], // 2 × 95.00
        'payments' => [['method' => 'cash', 'tendered' => 20000]],
        'offline_created_at' => now()->subMinutes(20)->toIso8601String(),
    ], $override);
}

it('syncs an offline sale once, never twice', function () {
    $s = posSalon();
    $sale = offlineSale($s);

    $first = $this->actingAs($s->owner)->postJson(tenantUrl($s->tenant, 'pos/sync'), ['sales' => [$sale]])
        ->assertOk()->assertJsonPath('results.0.status', 'created');

    // Flaky network: the tablet replays the same queue (and even the same sale twice in one batch).
    $this->actingAs($s->owner)->postJson(tenantUrl($s->tenant, 'pos/sync'), ['sales' => [$sale, $sale]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'duplicate')
        ->assertJsonPath('results.1.status', 'duplicate')
        ->assertJsonPath('results.0.sale_id', $first->json('results.0.sale_id'));

    asTenant($s->tenant, function () use ($s) {
        $sale = Sale::sole();
        expect($sale->is_offline)->toBeTrue()
            ->and($sale->offline_created_at)->not->toBeNull()
            ->and($sale->total)->toBe(19000)
            ->and($sale->change_due)->toBe(1000)
            ->and(Payment::where('payable_id', $sale->id)->count())->toBe(1)
            ->and(variantStock($s->products[0], $s->branch->id))->toBe(8);
    });
});

it('accepts offline sales that push stock below zero and reports the conflict', function () {
    $s = posSalon();
    $sale = offlineSale($s, ['lines' => [['type' => 'product', 'id' => $s->products[1]->id, 'qty' => 12]], 'payments' => [['method' => 'card_terminal', 'amount' => 144000, 'reference' => 'OFF-1']]]);

    $this->actingAs($s->owner)->postJson(tenantUrl($s->tenant, 'pos/sync'), ['sales' => [$sale]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'created')
        ->assertJsonPath('conflicts.0.product_variant_id', $s->products[1]->id)
        ->assertJsonPath('conflicts.0.balance_after', -2);

    asTenant($s->tenant, fn () => expect(Sale::sole()->sync_conflicts[0]['balance_after'])->toBe(-2));
});

it('rejects online-only tenders in offline sales', function () {
    $s = posSalon();
    $sale = offlineSale($s, ['payments' => [['method' => 'wallet', 'amount' => 19000]]]);

    $this->actingAs($s->owner)->postJson(tenantUrl($s->tenant, 'pos/sync'), ['sales' => [$sale]])
        ->assertOk()->assertJsonPath('results.0.status', 'failed');

    asTenant($s->tenant, fn () => expect(Sale::count())->toBe(0));
});

it('serves the PWA manifest, service worker and offline catalog', function () {
    $s = posSalon();

    $this->get(tenantUrl($s->tenant, 'pos/manifest.webmanifest'))->assertOk()->assertJsonPath('scope', '/pos/');
    $this->get(tenantUrl($s->tenant, 'pos/sw.js'))->assertOk()->assertHeader('Service-Worker-Allowed', '/pos/');
    $this->actingAs($s->owner)->getJson(tenantUrl($s->tenant, 'pos/catalog.json?branch='.$s->branch->id))
        ->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('items.0.type', 'service');
});

it('keeps the sync endpoint behind authentication', function () {
    $s = posSalon();
    $this->postJson(tenantUrl($s->tenant, 'pos/sync'), ['sales' => [offlineSale($s)]])->assertUnauthorized();
});
