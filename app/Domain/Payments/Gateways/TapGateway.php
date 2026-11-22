<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Tap Payments charges API. Credentials: secret_key. */
class TapGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'tap';
    }

    protected function http()
    {
        return Http::withToken($this->cred('secret_key'))->acceptJson()->baseUrl('https://api.tap.company/v2');
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        $name = $payload['customer']['name'] ?? 'Guest';
        $res = $this->http()->post('/charges', [
            'amount' => (float) $this->decimal($payload['amount'], $payload['currency']),
            'currency' => $payload['currency'],
            'description' => $payload['description'],
            'reference' => ['transaction' => $payload['reference'], 'order' => $payload['reference']],
            'customer' => ['first_name' => $name, 'email' => $payload['customer']['email'] ?? null],
            'source' => ['id' => 'src_all'],
            'redirect' => ['url' => $payload['return_url']],
            'post' => ['url' => $payload['webhook_url'] ?? null],
        ])->throw();

        return new CheckoutSession((string) $res->json('id'), $res->json('transaction.url'), null, 'pending', $res->json());
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $d = $request->all();
        $amount = number_format((float) ($d['amount'] ?? 0), 2, '.', '');
        $toHash = 'x_id'.($d['id'] ?? '').'x_amount'.$amount.'x_currency'.($d['currency'] ?? '').'x_gateway_reference'.($d['reference']['gateway'] ?? '').'x_payment_reference'.($d['reference']['payment'] ?? '').'x_status'.($d['status'] ?? '').'x_created'.($d['transaction']['created'] ?? '');
        if (! hash_equals(hash_hmac('sha256', $toHash, (string) $this->cred('secret_key')), (string) $request->header('hashstring'))) {
            throw new \RuntimeException('Invalid Tap signature');
        }

        return new WebhookEvent(
            eventId: (string) ($d['id'] ?? uniqid('tap_')).':'.($d['status'] ?? ''),
            type: 'charge',
            status: match ($d['status'] ?? '') { 'CAPTURED' => 'succeeded', 'FAILED', 'DECLINED', 'CANCELLED' => 'failed', default => 'pending' },
            reference: $d['id'] ?? null,
            merchantReference: $d['reference']['transaction'] ?? null,
            amount: isset($d['amount']) ? $this->toMinor($d['amount'], $d['currency'] ?? 'QAR') : null,
            raw: $d,
        );
    }

    public function status(string $reference): string
    {
        return match ($this->http()->get('/charges/'.$reference)->json('status')) { 'CAPTURED' => 'succeeded', 'FAILED', 'DECLINED', 'CANCELLED' => 'failed', default => 'pending' };
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        return $this->http()->post('/refunds', ['charge_id' => $reference, 'amount' => (float) $this->decimal($amount, $currency), 'currency' => $currency, 'reason' => 'requested_by_customer'])->successful();
    }

    public function testConnection(): array
    {
        if (! $this->cred('secret_key')) {
            return [false, __('salon.payments.missing_credentials')];
        }
        $res = $this->http()->get('/charges', ['limit' => 1]);

        return [$res->successful(), $res->successful() ? __('salon.payments.test_ok') : __('salon.payments.test_failed')];
    }
}
