<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\DTOs\Quote;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

class IssueInvoice
{
    /**
     * @param  list<array{type:string,description:string,quantity:int,unit_amount:int,amount:int,meta?:array}>|null  $lines
     */
    public function fromQuote(Tenant $tenant, Quote $quote, ?Subscription $subscription = null, string $type = 'subscription', int $dueInDays = 7): Invoice
    {
        $lines = $quote->lines;
        if ($quote->cycleDiscount > 0) {
            $lines[] = ['type' => 'discount', 'description' => __('salon.billing.line_cycle_discount', ['percent' => rtrim(rtrim(number_format($quote->cycleDiscountPercent, 2), '0'), '.')]), 'quantity' => 1, 'unit_amount' => -$quote->cycleDiscount, 'amount' => -$quote->cycleDiscount];
        }
        if ($quote->couponDiscount > 0) {
            $lines[] = ['type' => 'coupon', 'description' => __('salon.billing.line_coupon', ['code' => $quote->couponCode]), 'quantity' => 1, 'unit_amount' => -$quote->couponDiscount, 'amount' => -$quote->couponDiscount];
        }

        return $this->create($tenant, $lines, $quote->currency, $quote->taxPercent, $subscription, $type, $dueInDays, $quote->subtotal, $quote->discountTotal());
    }

    /** Ad-hoc invoice (credits, proration...) from raw lines. */
    public function create(Tenant $tenant, array $lines, string $currency = 'QAR', float $taxPercent = 0, ?Subscription $subscription = null, string $type = 'addon', int $dueInDays = 7, ?int $subtotal = null, ?int $discount = null): Invoice
    {
        return DB::transaction(function () use ($tenant, $lines, $currency, $taxPercent, $subscription, $type, $dueInDays, $subtotal, $discount) {
            $subtotal ??= array_sum(array_map(fn ($l) => max(0, $l['amount']), $lines));
            $discount ??= abs(array_sum(array_map(fn ($l) => min(0, $l['amount']), $lines)));
            $net = max(0, $subtotal - $discount);
            $tax = (int) round($net * $taxPercent / 100);

            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'number' => Invoice::nextNumber(),
                'type' => $type,
                'status' => $net + $tax === 0 ? InvoiceStatus::Paid : InvoiceStatus::Open,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'tax_percent' => $taxPercent,
                'tax_total' => $tax,
                'total' => $net + $tax,
                'issued_at' => now(),
                'due_at' => now()->addDays($dueInDays),
                'paid_at' => $net + $tax === 0 ? now() : null,
                'billing_details' => ['name' => $tenant->legal_name ?: $tenant->name, 'email' => $tenant->email, 'phone' => $tenant->phone, 'tax_number' => $tenant->tax_number, 'country' => $tenant->country],
            ]);

            foreach ($lines as $line) {
                $invoice->items()->create([
                    'type' => $line['type'], 'description' => $line['description'], 'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'], 'amount' => $line['amount'], 'meta' => $line['meta'] ?? null,
                ]);
            }

            return $invoice;
        });
    }
}
