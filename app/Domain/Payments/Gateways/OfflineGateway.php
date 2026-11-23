<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\DTOs\CheckoutSession;

/** Cash, card terminal, bank transfer and cash-on-delivery: recorded manually, no hosted page. */
class OfflineGateway extends AbstractGateway
{
    public function __construct(protected string $driver = 'cash', array $credentials = [], string $mode = 'live')
    {
        parent::__construct($credentials, $mode);
    }

    public function key(): string
    {
        return $this->driver;
    }

    public function createCheckout(array $payload): CheckoutSession
    {
        return new CheckoutSession($payload['reference'], null, null, 'pending');
    }

    public function status(string $reference): string
    {
        return 'pending';
    }

    public function refund(string $reference, int $amount, string $currency): bool
    {
        return true;
    }

    public function testConnection(): array
    {
        return [true, __('salon.payments.test_ok')];
    }
}
