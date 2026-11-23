<?php

namespace App\Domain\AIStudio\Listeners;

use App\Domain\AIStudio\Actions\PurchaseCreditPack;
use App\Domain\AIStudio\Events\AICreditsLow;
use App\Domain\AIStudio\Models\AIWallet;
use App\Domain\AIStudio\Notifications\AICreditsLowNotification;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Throwable;

/** Low-balance alert to the owner + optional auto top-up (issues a credits invoice once per open invoice). */
class HandleLowCredits
{
    public function handle(AICreditsLow $event): void
    {
        $tenant = Tenant::find($event->tenantId);
        if (! $tenant) {
            return;
        }

        app(TenantContext::class)->run($tenant, function () use ($tenant, $event) {
            $wallet = AIWallet::for($tenant);
            $invoiceNumber = null;
            if ($wallet->auto_topup && $wallet->autoTopupPack) {
                $open = $tenant->invoices()->where('type', 'credits')->where('status', InvoiceStatus::Open->value)->exists();
                if (! $open) {
                    $invoiceNumber = app(PurchaseCreditPack::class)->handle($tenant, $wallet->autoTopupPack)->number;
                }
            }
            try {
                $tenant->owner?->notify(new AICreditsLowNotification($event->balance, $tenant->url('dashboard'), $invoiceNumber));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
