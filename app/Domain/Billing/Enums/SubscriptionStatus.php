<?php

namespace App\Domain\Billing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return __('salon.subscription_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trialing, self::Pending => 'info',
            self::Active => 'success',
            self::PastDue, self::Grace => 'warning',
            default => 'danger',
        };
    }

    /** Statuses whose modules are entitled. */
    public static function entitled(): array
    {
        return [self::Trialing, self::Active, self::PastDue, self::Grace];
    }
}
