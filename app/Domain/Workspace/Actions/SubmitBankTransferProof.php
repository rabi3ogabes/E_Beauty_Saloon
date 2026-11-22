<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PlatformPayment;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;

/**
 * Owner pays an open platform invoice by bank transfer: the proof (PDF/image) is stored on the
 * private disk and a PlatformPayment "awaiting_approval" is created for the finance team (/admin).
 */
class SubmitBankTransferProof
{
    public function handle(Invoice $invoice, UploadedFile|string $proof, ?string $reference = null): PlatformPayment
    {
        abort_unless($invoice->tenant_id === tenant()?->id, 404);

        if ($invoice->status !== InvoiceStatus::Open || $invoice->balanceDue() <= 0) {
            throw new DomainException(__('workspace.billing.invoice_not_open'));
        }
        if ($invoice->payments()->where('status', 'awaiting_approval')->exists()) {
            throw new DomainException(__('workspace.billing.proof_pending'));
        }

        $path = $proof instanceof UploadedFile
            ? $proof->store('billing-proofs/'.$invoice->tenant_id, 'local')
            : $proof;

        $payment = PlatformPayment::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'gateway' => 'bank_transfer',
            'status' => 'awaiting_approval',
            'amount' => $invoice->balanceDue(),
            'currency' => $invoice->currency,
            'reference' => $reference,
            'proof_path' => $path,
            'payload' => ['submitted_by' => auth()->id()],
        ]);

        activity()->performedOn($invoice)->causedBy(auth()->user())->withProperties(['payment_id' => $payment->id])->log('billing.proof_submitted');

        return $payment;
    }
}
