<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** SkipCash Qatar. Credentials: client_id, key_id, key_secret, webhook_key. */
class SkipCashGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'skipcash';
    }

    protected function base(): string
    {
        return $this->isLive() ? 'https://api.skipcash.app' : 'https://skipcashtest.azurewebsites.net';
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        $name = $payload['customer']['name'] ?? 'Guest Client';
        $fields = [
            'Uid' => (string) Str::uuid(),
            'KeyId' => $this->cred('key_id'),
            'Amount' => $this->decimal($payload['amount'], $payload['currency']),
            'FirstName' => Str::before($name, ' ') ?: 'Guest',
            'LastName' => Str::after($name, ' ') ?: 'Client',
            'Phone' => $payload['customer']['phone'] ?? '',
            'Email' => $payload['customer']['email'] ?? 'guest@example.com',
            'TransactionId' => $payload['reference'],
        ];
        $signature = base64_encode(hash_hmac('sha256', collect($fields)->map(fn ($v, $k) => "$k=$v")->implode(','), (string) $this->cred('key_secret'), true));

        $res = Http::withHeaders(['Authorization' => $signature])->acceptJson()
            ->post($this->base().'/api/v1/payments', $fields + ['ReturnUrl' => $payload['return_url'], 'WebhookUrl' => $payload['webhook_url'] ?? null])->throw();

        return new CheckoutSession((string) $res->json('resultObj.id'), $res->json('resultObj.payUrl'), $res->json('resultObj.payUrl'), 'pending', $res->json());
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $d = $request->all();
        if ($key = $this->cred('webhook_key')) {
            $string = collect(['PaymentId', 'Amount', 'StatusId', 'TransactionId', 'Custom1', 'VisaId'])
                ->filter(fn ($k) => isset($d[$k]))->map(fn ($k) => "$k={$d[$k]}")->implode(',');
            if (! hash_equals(base64_encode(hash_hmac('sha256', $string, $key, true)), (string) $request->header('Authorization'))) {
                throw new \RuntimeException('Invalid SkipCash signature');
            }
        }

        return new WebhookEvent(
            eventId: (string) ($d['PaymentId'] ?? uniqid('sc_')).':'.($d['StatusId'] ?? ''),
            type: 'payment',
            status: match ((int) ($d['StatusId'] ?? 0)) { 2 => 'succeeded', 3, 4, 5 => 'failed', 6 => 'refunded', default => 'pending' },
            reference: $d['PaymentId'] ?? null,
            merchantReference: $d['TransactionId'] ?? null,
            amount: isset($d['Amount']) ? $this->toMinor($d['Amount'], 'QAR') : null,
            raw: $d,
        );
    }

    public function status(string $reference): string
    {
        $res = Http::withHeaders(['Authorization' => $this->cred('client_id')])->get($this->base().'/api/v1/payments/'.$reference);

        return match ((int) $res->json('resultObj.statusId')) { 2 => 'succeeded', 3, 4, 5 => 'failed', default => 'pending' };
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        return Http::withHeaders(['Authorization' => $this->cred('client_id')])
            ->post($this->base().'/api/v1/payments/refund', ['id' => $reference, 'amount' => $this->decimal($amount, $currency)])->successful();
    }

    public function testConnection(): array
    {
        $ok = (bool) ($this->cred('client_id') && $this->cred('key_id') && $this->cred('key_secret'));

        return [$ok, $ok ? __('salon.payments.test_ok') : __('salon.payments.missing_credentials')];
    }
}
