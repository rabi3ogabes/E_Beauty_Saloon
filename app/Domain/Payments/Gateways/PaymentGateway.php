<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Strategy interface for every payment provider. Hosted payment pages only —
 * card data never touches our servers (minimal PCI scope).
 */
interface PaymentGateway
{
    public function key(): string;

    /**
     * @param  array{amount:int,currency:string,reference:string,description:string,customer?:array,return_url:string,cancel_url?:string,webhook_url?:string}  $payload
     */
    public function createCheckout(array $payload): CheckoutSession;

    /** Validate signature & parse; throw on invalid signature. */
    public function parseWebhook(Request $request): WebhookEvent;

    /** Query provider for the current status of a payment reference. */
    public function status(string $reference): string;

    public function refund(string $reference, int $amount, string $currency): bool;

    /** "Test connection" button. Returns [ok, message]. */
    public function testConnection(): array;
}
