<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;
use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * SADAD Qatar (web checkout). Credentials: merchant_id, secret_key, website (registered domain).
 * Signature: SHA-256 checksum of the sorted parameters with the secret key, as required by
 * SADAD's checkout v2.1 integration guide. Verify the exact field list against the merchant
 * portal before going live.
 */
class SadadGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'sadad';
    }

    protected function baseUrl(): string
    {
        return $this->isLive() ? 'https://sadadqa.com' : 'https://sadadqa.com/webpurchase';
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        $params = [
            'merchant_id' => $this->cred('merchant_id'),
            'ORDER_ID' => $payload['reference'],
            'WEBSITE' => $this->cred('website'),
            'TXN_AMOUNT' => $this->decimal($payload['amount'], $payload['currency']),
            'CUST_ID' => $payload['customer']['email'] ?? $payload['customer']['phone'] ?? 'guest',
            'EMAIL' => $payload['customer']['email'] ?? '',
            'MOBILE_NO' => ltrim($payload['customer']['phone'] ?? '', '+'),
            'SADAD_WEBCHECKOUT_PAGE_LANGUAGE' => app()->getLocale() === 'ar' ? 'Arb' : 'ENG',
            'CALLBACK_URL' => $payload['webhook_url'] ?? $payload['return_url'],
            'txnDate' => now()->format('Y-m-d H:i:s'),
        ];
        $params['checksumhash'] = $this->checksum($params);

        // SADAD web checkout is a form POST from the browser; we return a signed redirect to our auto-submit page.
        $url = route('payments.redirect', ['to' => encrypt(['action' => $this->baseUrl().'/webpurchase/request', 'fields' => $params])]);

        return new CheckoutSession($payload['reference'], $url, null, 'pending', $params);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->all();
        $hash = $data['checksumhash'] ?? '';
        unset($data['checksumhash']);
        if (! hash_equals($this->checksum($data), (string) $hash)) {
            throw new \RuntimeException('Invalid SADAD checksum');
        }
        $ok = ($data['RESPCODE'] ?? '') === '1';

        return new WebhookEvent(
            eventId: (string) ($data['transaction_number'] ?? $data['ORDERID'] ?? uniqid('sadad_')),
            type: 'payment',
            status: $ok ? 'succeeded' : 'failed',
            reference: $data['transaction_number'] ?? null,
            merchantReference: $data['ORDERID'] ?? $data['ORDER_ID'] ?? null,
            amount: isset($data['TXNAMOUNT']) ? $this->toMinor($data['TXNAMOUNT'], 'QAR') : null,
            raw: $data,
        );
    }

    public function status(string $reference): string
    {
        $res = Http::asJson()->post('https://api-s.sadad.qa/api/transactions/getTransaction', [
            'merchant_id' => $this->cred('merchant_id'), 'order_id' => $reference,
        ]);

        return match ((string) $res->json('transactionstatus')) {
            '3' => 'succeeded', '2' => 'failed', default => 'pending',
        };
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        $res = Http::asJson()->post('https://api-s.sadad.qa/api/transactions/refundTransaction', [
            'merchant_id' => $this->cred('merchant_id'), 'transaction_number' => $reference,
            'amount' => $this->decimal($amount, $currency), 'secret_key' => $this->cred('secret_key'),
        ]);

        return $res->successful();
    }

    public function testConnection(): array
    {
        if (! $this->cred('merchant_id') || ! $this->cred('secret_key')) {
            return [false, __('salon.payments.missing_credentials')];
        }
        $ok = Http::timeout(8)->get($this->baseUrl())->status() < 500;

        return [$ok, $ok ? __('salon.payments.test_ok') : __('salon.payments.test_unreachable')];
    }

    protected function checksum(array $params): string
    {
        ksort($params);
        $string = $this->cred('secret_key').implode('', array_map('strval', $params));

        return hash('sha256', $string);
    }
}
