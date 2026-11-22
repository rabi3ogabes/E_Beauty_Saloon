<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Stripe Checkout Sessions via REST (no SDK dependency). Credentials: secret_key, webhook_secret. */
class StripeGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'stripe';
    }

    protected function http()
    {
        return Http::withToken($this->cred('secret_key'))->asForm()->baseUrl('https://api.stripe.com/v1');
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        $res = $this->http()->withHeaders(['Idempotency-Key' => $payload['reference']])->post('/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $payload['reference'],
            'success_url' => $payload['return_url'],
            'cancel_url' => $payload['cancel_url'] ?? $payload['return_url'],
            'customer_email' => $payload['customer']['email'] ?? null,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payload['currency']),
                    'unit_amount' => $payload['amount'],
                    'product_data' => ['name' => $payload['description']],
                ],
            ]],
            'metadata' => ['reference' => $payload['reference']],
            'payment_intent_data' => ['metadata' => ['reference' => $payload['reference']]],
        ])->throw();

        return new CheckoutSession($res->json('id'), $res->json('url'), null, 'pending', $res->json());
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $this->verifySignature($request);
        $event = $request->json()->all();
        $object = $event['data']['object'] ?? [];
        $status = match ($event['type'] ?? '') {
            'checkout.session.completed', 'payment_intent.succeeded' => 'succeeded',
            'payment_intent.payment_failed', 'checkout.session.expired' => 'failed',
            'charge.refunded' => 'refunded',
            default => 'pending',
        };

        return new WebhookEvent(
            eventId: $event['id'] ?? uniqid('evt_'),
            type: $event['type'] ?? 'unknown',
            status: $status,
            reference: $object['id'] ?? null,
            merchantReference: $object['client_reference_id'] ?? ($object['metadata']['reference'] ?? null),
            amount: $object['amount_total'] ?? $object['amount'] ?? null,
            raw: $event,
        );
    }

    protected function verifySignature(Request $request): void
    {
        $secret = $this->cred('webhook_secret');
        if (! $secret) {
            return;
        }
        $parts = collect(explode(',', (string) $request->header('Stripe-Signature')))
            ->mapWithKeys(fn ($p) => [explode('=', $p, 2)[0] => explode('=', $p, 2)[1] ?? '']);
        $expected = hash_hmac('sha256', $parts->get('t').'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, (string) $parts->get('v1')) || abs(time() - (int) $parts->get('t')) > 300) {
            throw new \RuntimeException('Invalid Stripe signature');
        }
    }

    public function status(string $reference): string
    {
        $session = $this->http()->get('/checkout/sessions/'.$reference)->json();

        return ($session['payment_status'] ?? null) === 'paid' ? 'succeeded' : (($session['status'] ?? '') === 'expired' ? 'failed' : 'pending');
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        $intent = $this->http()->get('/checkout/sessions/'.$reference)->json('payment_intent') ?? $reference;

        return $this->http()->post('/refunds', ['payment_intent' => $intent, 'amount' => $amount])->successful();
    }

    public function testConnection(): array
    {
        if (! $this->cred('secret_key')) {
            return [false, __('salon.payments.missing_credentials')];
        }
        $res = $this->http()->get('/balance');

        return [$res->successful(), $res->successful() ? __('salon.payments.test_ok') : (string) $res->json('error.message', __('salon.payments.test_failed'))];
    }
}
