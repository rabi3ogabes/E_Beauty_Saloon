<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TenantStatus: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('salon.tenant_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Grace => 'warning',
            self::Suspended, self::Cancelled => 'danger',
        };
    }

    /** Dashboard is usable (writes allowed). */
    public function canOperate(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }

    /** Dashboard is visible at all (grace = read-only). */
    public function canAccessDashboard(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::Grace], true);
    }
}
