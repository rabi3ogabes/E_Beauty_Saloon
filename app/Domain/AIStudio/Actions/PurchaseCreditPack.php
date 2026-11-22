<?php

namespace App\Domain\AIStudio\Actions;

use App\Domain\AIStudio\Models\AICreditPack;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Exceptions\DomainException;

/**
 * Salon buys a credit pack → platform invoice of type "credits". Credits land in the wallet when the
 * invoice is paid (MarkInvoicePaid → Invoice updated listener → CreditLedger, idempotent per invoice).
 */
class PurchaseCreditPack
{
    public function __construct(protected IssueInvoice $invoices) {}

    public function handle(Tenant $tenant, AICreditPack $pack): Invoice
    {
        if (! $pack->refresh()->is_active) {
            throw new DomainException(__('ai_studio.errors.pack_unavailable'));
        }
        abort_if($tenant->isReadOnly(), 423);

        $invoice = $this->invoices->create($tenant, [[
            'type' => 'credits',
            'description' => __('ai_studio.wallet.invoice_line', ['credits' => number_format($pack->credits), 'pack' => $pack->getTranslation('name', app()->getLocale())]),
            'quantity' => 1,
            'unit_amount' => (int) $pack->price,
            'amount' => (int) $pack->price,
            'meta' => ['ai_credit_pack_id' => $pack->id, 'credits' => (int) $pack->credits],
        ]], $pack->currency, (float) config('salon.defaults.tax_percent'), null, 'credits');

        activity()->performedOn($invoice)->withProperties(['pack' => $pack->id, 'credits' => $pack->credits])->log('ai.credits.purchased');

        // Free packs (price 0) are paid on issue — credit immediately.
        if ($invoice->status->value === 'paid') {
            app(ApplyCreditInvoice::class)->handle($invoice);
        }

        return $invoice;
    }
}
