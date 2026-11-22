<?php

namespace App\Domain\AIStudio\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AICreditsLow
{
    use Dispatchable;

    public function __construct(public int $tenantId, public int $balance) {}
}
