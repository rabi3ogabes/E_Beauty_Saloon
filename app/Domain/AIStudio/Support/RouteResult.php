<?php

namespace App\Domain\AIStudio\Support;

use App\Domain\AIStudio\Models\AIProvider;

final class RouteResult
{
    public function __construct(
        public readonly mixed $result,
        public readonly AIProvider $provider,
        public readonly int $latencyMs,
        public readonly array $attempts,
    ) {}
}
