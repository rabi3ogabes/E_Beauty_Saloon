<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Deterministic gateway for tests & local demo. Swap in with GatewayManager::fake().
 * Its hosted page is our own /payments/fake/{reference} confirmation screen.
 */
class FakeGateway extends AbstractGateway
{
    public static array $refunds = [];

    public static array $checkouts = [];

    public function key(): string
    {
        return 'fake';
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        static::$checkouts[] = $payload;

        return new CheckoutSession('fake_'.$payload['reference'], url('/payments/fake/'.$payload['reference']), 'FAKEQR:'.$payload['reference']);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        return new WebhookEvent(
            eventId: (string) $request->input('event_id', uniqid('fake_')),
            type: 'payment',
            status: (string) $request->input('status', 'succeeded'),
            reference: $request->input('reference'),
            merchantReference: $request->input('merchant_reference'),
            amount: $request->integer('amount') ?: null,
            raw: $request->all(),
        );
    }

    public function status(string $reference): string
    {
        return 'succeeded';
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        static::$refunds[] = compact('reference', 'amount', 'currency');

        return true;
    }

    public function testConnection(): array
    {
        return [true, 'Fake gateway OK'];
    }
}
