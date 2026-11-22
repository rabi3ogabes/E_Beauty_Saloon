<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PlatformPayment;
use Illuminate\Support\Facades\DB;

/**
 * Records a platform payment against an invoice (idempotent by key) and activates /
 * renews / applies changes to the related subscription once fully paid.
 */
class MarkInvoicePaid
{
    public function __construct(protected ActivateSubscription $activate) {}

    public function handle(Invoice $invoice, string $gateway = 'manual', ?string $reference = null, ?string $idempotencyKey = null, ?int $amount = null, ?int $approvedBy = null): PlatformPayment
    {
        return DB::transaction(function () use ($invoice, $gateway, $reference, $idempotencyKey, $amount, $approvedBy) {
            if ($idempotencyKey && ($existing = PlatformPayment::where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $amount ??= $invoice->balanceDue();

            $payment = PlatformPayment::create([
                'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'gateway' => $gateway,
                'status' => 'succeeded', 'amount' => $amount, 'currency' => $invoice->currency,
                'reference' => $reference, 'idempotency_key' => $idempotencyKey, 'approved_by' => $approvedBy, 'paid_at' => now(),
            ]);

            $invoice->amount_paid += $amount;
            if ($invoice->amount_paid >= $invoice->total && $invoice->status !== InvoiceStatus::Paid) {
                $invoice->status = InvoiceStatus::Paid;
                $invoice->paid_at = now();
                $invoice->save();
                $this->applyToSubscription($invoice);
            } else {
                $invoice->save();
            }

            activity()->performedOn($invoice)->withProperties(['amount' => $amount, 'gateway' => $gateway])->log('invoice.paid');

            return $payment;
        });
    }

    protected function applyToSubscription(Invoice $invoice): void
    {
        $subscription = $invoice->subscription;
        if (! $subscription) {
            return;
        }

        match ($invoice->type) {
            'renewal' => $this->activate->handle($subscription, extendCycle: true),
            'proration' => app(ChangeSubscription::class)->applyPaidChange($invoice),
            default => $this->activate->handle($subscription),
        };
    }
}
