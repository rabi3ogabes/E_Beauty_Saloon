<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\WebhookEvent;
use Illuminate\Http\Request;

abstract class AbstractGateway implements PaymentGateway
{
    public function __construct(protected array $credentials = [], protected string $mode = 'test') {}

    protected function cred(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }

    protected function isLive(): bool
    {
        return $this->mode === 'live';
    }

    /** Minor units -> decimal string the provider expects. */
    protected function decimal(int $minor, string $currency): string
    {
        return \App\Support\Money\Money::of($minor, $currency)->toDecimal();
    }

    protected function toMinor(string|float|int $decimal, string $currency): int
    {
        return \App\Support\Money\Money::fromDecimal($decimal, $currency)->amount;
    }

    protected function requireHmac(Request $request, string $header, string $secret, string $algo = 'sha256'): void
    {
        $expected = hash_hmac($algo, $request->getContent(), $secret);
        if (! hash_equals($expected, (string) $request->header($header))) {
            throw new \RuntimeException('Invalid webhook signature');
        }
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new \RuntimeException(static::class.' does not support webhooks');
    }
}
