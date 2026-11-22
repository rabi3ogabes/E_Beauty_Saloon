<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\Billing\Models\Invoice;

/** Credits the wallet for a paid "credits" invoice. Idempotent per invoice. */
class ApplyCreditInvoice
{
    public function __construct(protected CreditLedger $ledger) {}

    public function handle(Invoice $invoice): void
    {
        if ($invoice->type !== 'credits' || $invoice->status?->value !== 'paid') {
            return;
        }
        $credits = (int) $invoice->items()->get()->sum(fn ($item) => (int) data_get($item->meta, 'credits', 0));
        if ($credits <= 0 || ! $invoice->tenant) {
            return;
        }

        $this->ledger->credit($invoice->tenant, $credits, 'purchase', 'invoice:'.$invoice->id,
            __('ai_studio.wallet.purchase', ['number' => $invoice->number]), 'invoice', $invoice->id);
    }
}
