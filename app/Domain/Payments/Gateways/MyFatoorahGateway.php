<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** MyFatoorah (Qatar portal). Credentials: api_key, webhook_secret. */
class MyFatoorahGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'myfatoorah';
    }

    protected function http()
    {
        $base = $this->isLive() ? 'https://api-qa.myfatoorah.com' : 'https://apitest.myfatoorah.com';

        return Http::withToken($this->cred('api_key'))->acceptJson()->baseUrl($base.'/v2');
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        $res = $this->http()->post('/SendPayment', [
            'NotificationOption' => 'LNK',
            'InvoiceValue' => (float) $this->decimal($payload['amount'], $payload['currency']),
            'DisplayCurrencyIso' => $payload['currency'],
            'CustomerName' => $payload['customer']['name'] ?? 'Guest',
            'CustomerEmail' => $payload['customer']['email'] ?? null,
            'CustomerReference' => $payload['reference'],
            'CallBackUrl' => $payload['return_url'],
            'ErrorUrl' => $payload['cancel_url'] ?? $payload['return_url'],
            'Language' => app()->getLocale() === 'ar' ? 'ar' : 'en',
        ])->throw();

        return new CheckoutSession((string) $res->json('Data.InvoiceId'), $res->json('Data.InvoiceURL'), null, 'pending', $res->json());
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if ($secret = $this->cred('webhook_secret')) {
            $data = $request->input('Data', []);
            ksort($data);
            $string = collect($data)->map(fn ($v, $k) => $k.'='.(is_scalar($v) ? $v : ''))->implode(',');
            $expected = base64_encode(hash_hmac('sha256', $string, $secret, true));
            if (! hash_equals($expected, (string) $request->header('MyFatoorah-Signature'))) {
                throw new \RuntimeException('Invalid MyFatoorah signature');
            }
        }
        $d = $request->input('Data', []);

        return new WebhookEvent(
            eventId: (string) ($d['InvoiceId'] ?? '').':'.($d['TransactionStatus'] ?? ''),
            type: (string) $request->input('EventType', 'TransactionsStatusChanged'),
            status: match ($d['TransactionStatus'] ?? '') {
                'SUCCESS' => 'succeeded', 'FAILED', 'CANCELED' => 'failed', default => 'pending'
            },
            reference: isset($d['InvoiceId']) ? (string) $d['InvoiceId'] : null,
            merchantReference: $d['CustomerReference'] ?? null,
            raw: $request->all(),
        );
    }

    public function status(string $reference): string
    {
        $res = $this->http()->post('/GetPaymentStatus', ['Key' => $reference, 'KeyType' => 'InvoiceId']);

        return match ($res->json('Data.InvoiceStatus')) {
            'Paid' => 'succeeded', 'Expired', 'Failed' => 'failed', default => 'pending'
        };
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        return $this->http()->post('/MakeRefund', ['KeyType' => 'InvoiceId', 'Key' => $reference, 'Amount' => (float) $this->decimal($amount, $currency)])->successful();
    }

    public function testConnection(): array
    {
        if (! $this->cred('api_key')) {
            return [false, __('salon.payments.missing_credentials')];
        }
        $res = $this->http()->post('/InitiatePayment', ['InvoiceAmount' => 1, 'CurrencyIso' => 'QAR']);

        return [$res->successful() && $res->json('IsSuccess'), $res->successful() ? __('salon.payments.test_ok') : __('salon.payments.test_failed')];
    }
}
