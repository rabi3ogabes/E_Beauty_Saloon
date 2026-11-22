<?php

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\POS\Models\Sale;
use App\Domain\Team\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/../Booking/Helpers.php';

/*
 * Controlled fixtures for the smart layer. Times are branch-local (Asia/Qatar, UTC+3).
 */
if (! function_exists('insightsSalon')) {
    function insightsSalon(array $opts = []): object
    {
        $salon = bookingSalon($opts);
        asTenant($salon->tenant, function () use ($salon) {
            $salon->second = Branch::create(['name' => ['en' => 'Pearl', 'ar' => 'اللؤلؤة'], 'slug' => 'pearl', 'timezone' => 'Asia/Qatar', 'opening_hours' => Branch::defaultHours(), 'sort' => 1]);
            $product = Product::create(['name' => ['en' => 'Silk Oil', 'ar' => 'زيت الحرير'], 'slug' => 'silk-oil-'.Str::random(4)]);
            $salon->product = $product;
            $salon->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SILK-'.Str::random(4), 'price' => 15000, 'cost' => 6000, 'is_default' => true]);
        });

        return $salon;
    }

    /** A booking with one item per service, back to back, for one staff member. */
    function insightsBooking(object $salon, string $localStart, array $serviceKeys = ['cut'], ?Staff $staff = null, BookingStatus $status = BookingStatus::Completed, ?Client $client = null, ?Branch $branch = null): Booking
    {
        return asTenant($salon->tenant, function () use ($salon, $localStart, $serviceKeys, $staff, $status, $client, $branch) {
            $branch ??= $salon->branch;
            $staff ??= $salon->staff[0];
            $start = CarbonImmutable::parse($localStart, 'Asia/Qatar')->utc();
            $cursor = $start;
            $total = 0;
            $items = [];
            foreach ($serviceKeys as $key) {
                $service = $salon->services[$key];
                $items[] = ['service_id' => $service->id, 'staff_id' => $staff->id, 'starts_at' => $cursor, 'ends_at' => $cursor->addMinutes($service->duration_minutes),
                    'price' => $service->price, 'duration_minutes' => $service->duration_minutes];
                $cursor = $cursor->addMinutes($service->duration_minutes);
                $total += $service->price;
            }
            $booking = Booking::create([
                'branch_id' => $branch->id, 'client_id' => $client?->id, 'status' => $status, 'source' => 'dashboard',
                'starts_at' => $start, 'ends_at' => $cursor, 'subtotal' => $total, 'total' => $total,
                'completed_at' => $status === BookingStatus::Completed ? $cursor : null,
            ]);
            foreach ($items as $i => $item) {
                $booking->items()->create($item + ['sort' => $i]);
            }

            return $booking;
        });
    }

    /**
     * A completed POS sale. $lines: [['service' => 'cut', 'staff' => 0, 'total' => 20000], ['product' => true, 'qty' => 2, 'total' => 30000]].
     */
    function insightsSale(object $salon, string $localAt, array $lines, int $tips = 0, int $refunded = 0, ?Branch $branch = null, ?Client $client = null, array $refundedQty = []): Sale
    {
        return asTenant($salon->tenant, function () use ($salon, $localAt, $lines, $tips, $refunded, $branch, $client, $refundedQty) {
            $branch ??= $salon->branch;
            $at = CarbonImmutable::parse($localAt, 'Asia/Qatar')->utc();
            $total = array_sum(array_column($lines, 'total')) + $tips;
            $sale = new Sale;
            $sale->forceFill([
                'tenant_id' => $salon->tenant->id, 'branch_id' => $branch->id, 'client_id' => $client?->id, 'status' => $refunded >= $total - $tips && $refunded > 0 ? 'refunded' : ($refunded ? 'partially_refunded' : 'completed'),
                'subtotal' => $total - $tips, 'total' => $total, 'tips' => $tips, 'paid' => $total, 'refunded_total' => $refunded,
                'idempotency_key' => (string) Str::uuid(), 'completed_at' => $at, 'currency' => 'QAR',
            ])->save();
            foreach ($lines as $i => $line) {
                $isService = isset($line['service']);
                $model = $isService ? $salon->services[$line['service']] : $salon->variant;
                $qty = $line['qty'] ?? 1;
                $sale->items()->create([
                    'type' => $isService ? 'service' : 'product', 'sellable_type' => $model->getMorphClass(), 'sellable_id' => $model->id,
                    'name' => $isService ? (string) $model->name : 'Silk Oil', 'qty' => $qty, 'refunded_qty' => $refundedQty[$i] ?? 0,
                    'unit_price' => intdiv($line['total'], $qty), 'total' => $line['total'],
                    'staff_id' => isset($line['staff']) ? $salon->staff[$line['staff']]->id : null,
                ]);
            }

            return $sale;
        });
    }

    function insightsClient(object $salon, string $phone, string $name = 'Client', array $attrs = []): Client
    {
        return asTenant($salon->tenant, fn () => Client::create(array_merge(['name' => $name, 'phone' => $phone], $attrs)));
    }
}
