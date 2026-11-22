<?php

namespace App\Domain\Booking\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasIcon, HasLabel
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InService = 'in_service';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case CancelledByClient = 'cancelled_by_client';
    case CancelledBySalon = 'cancelled_by_salon';

    public function getLabel(): string
    {
        return __('booking.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Confirmed => 'info',
            self::CheckedIn, self::InService => 'primary',
            self::Completed => 'success',
            self::NoShow, self::CancelledByClient, self::CancelledBySalon => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PendingPayment => 'heroicon-m-clock',
            self::Confirmed => 'heroicon-m-check',
            self::CheckedIn => 'heroicon-m-hand-raised',
            self::InService => 'heroicon-m-sparkles',
            self::Completed => 'heroicon-m-check-badge',
            default => 'heroicon-m-x-mark',
        };
    }

    /** Statuses that occupy the calendar (block availability). */
    public static function blocking(): array
    {
        return [self::PendingPayment, self::Confirmed, self::CheckedIn, self::InService, self::Completed];
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::CancelledByClient, self::CancelledBySalon], true);
    }

    /** @return list<self> allowed next statuses */
    public function transitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Confirmed, self::CancelledByClient, self::CancelledBySalon],
            self::Confirmed => [self::CheckedIn, self::NoShow, self::CancelledByClient, self::CancelledBySalon, self::Completed],
            self::CheckedIn => [self::InService, self::Completed, self::CancelledBySalon],
            self::InService => [self::Completed],
            default => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }
}
