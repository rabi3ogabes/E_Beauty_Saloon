<?php

namespace App\Domain\POS\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Completed = 'completed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return __('pos.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Completed => 'success',
            self::PartiallyRefunded => 'warning',
            self::Refunded, self::Voided => 'danger',
        };
    }

    /** Statuses that count as revenue (refunds are subtracted separately). */
    public static function settled(): array
    {
        return [self::Completed->value, self::PartiallyRefunded->value, self::Refunded->value, self::Voided->value];
    }
}
