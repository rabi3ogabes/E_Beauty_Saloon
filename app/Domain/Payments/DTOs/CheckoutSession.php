<?php

namespace App\Domain\Payments\DTOs;

final class CheckoutSession
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $qrPayload = null,
        public readonly string $status = 'pending',
        public readonly array $raw = [],
    ) {}
}
