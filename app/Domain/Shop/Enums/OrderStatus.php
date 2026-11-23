<?php

namespace App\Domain\Shop\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /** Allowed next statuses. */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::ReadyForPickup, self::OutForDelivery, self::Cancelled],
            self::Preparing => [self::ReadyForPickup, self::OutForDelivery, self::Cancelled],
            self::ReadyForPickup => [self::Delivered, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Preparing, self::Cancelled],
            self::Delivered => [self::Returned],
            self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }

    /** Statuses still holding stock / awaiting fulfilment. */
    public static function open(): array
    {
        return [self::Pending->value, self::Confirmed->value, self::Preparing->value, self::ReadyForPickup->value, self::OutForDelivery->value];
    }

    public function label(?string $locale = null): string
    {
        return __('shop.status.'.$this->value, [], $locale);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Preparing => 'primary',
            self::ReadyForPickup, self::OutForDelivery => 'primary',
            self::Delivered => 'success',
            self::Cancelled, self::Returned => 'danger',
        };
    }
}
