<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PlatformPayment;
use App\Domain\Payments\Gateways\GatewayManager;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Pay an open platform invoice online through a platform gateway (credentials in
 * config/services.php platform_gateways). The webhook (PlatformGatewayWebhookController) settles it.
 *
 * @return string hosted checkout URL
 */
class StartPlatformCheckout
{
    public function __construct(protected GatewayManager $gateways) {}

    /** Platform gateways with credentials configured. */
    public static function availableDrivers(): array
    {
        return collect(['sadad', 'skipcash', 'myfatoorah', 'stripe'])
            ->filter(fn ($d) => collect(config("services.platform_gateways.$d", []))->filter()->isNotEmpty())
            ->values()->all();
    }

    public function handle(Invoice $invoice, string $driver): string
    {
        abort_unless($invoice->tenant_id === tenant()?->id, 404);
        if (! in_array($driver, static::availableDrivers(), true)) {
            throw new DomainException(__('workspace.billing.gateway_unavailable'));
        }
        if ($invoice->status !== InvoiceStatus::Open || $invoice->balanceDue() <= 0) {
            throw new DomainException(__('workspace.billing.invoice_not_open'));
        }

        $reference = 'PLT-'.$invoice->id.'-'.Str::upper(Str::random(8));
        $payment = PlatformPayment::create([
            'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'gateway' => $driver, 'status' => 'pending',
            'amount' => $invoice->balanceDue(), 'currency' => $invoice->currency, 'idempotency_key' => $reference,
        ]);

        $session = $this->gateways->forPlatform($driver)->createCheckout([
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reference' => $reference,
            'description' => __('workspace.billing.checkout_description', ['number' => $invoice->number]),
            'customer' => ['email' => auth()->user()?->email, 'phone' => tenant()?->phone],
            'return_url' => url('/dashboard/billing'),
            'webhook_url' => route('webhooks.platform', ['driver' => $driver]),
        ]);
        $payment->update(['reference' => $session->reference]);

        return (string) $session->redirectUrl;
    }
}
