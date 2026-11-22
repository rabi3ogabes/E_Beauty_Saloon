<?php

namespace App\Domain\Billing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return __('salon.invoice_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'warning',
            self::Paid => 'success',
            self::Void, self::Refunded => 'danger',
        };
    }
}
