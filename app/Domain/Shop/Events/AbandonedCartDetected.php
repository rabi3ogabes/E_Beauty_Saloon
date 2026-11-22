<?php

namespace App\Domain\Shop\Events;

use App\Domain\Shop\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A cart with a known client/phone has been idle longer than the threshold (default 2h).
 * Emitted once per idle period by DetectAbandonedCarts. Marketing automations listen to this.
 * Payload: cart (with items.variant.product), tenantId, phone, clientId, subtotal (minor units), recoveryUrl.
 */
class AbandonedCartDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public int $tenantId,
        public ?string $phone,
        public ?int $clientId,
        public int $subtotal,
        public string $recoveryUrl,
    ) {}
}
