<?php

namespace App\Domain\Messaging\DTOs;

final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly int $costUnits = 0,
    ) {}
}
