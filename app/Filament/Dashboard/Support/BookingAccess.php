<?php

namespace App\Filament\Dashboard\Support;

use App\Domain\Team\Models\Staff;

/** Dashboard gates for the booking module (bookings.view / bookings.manage / bookings.own_only). */
class BookingAccess
{
    public static function canView(): bool
    {
        return (bool) (tenant()?->hasModule('booking') && auth()->user()?->can('bookings.view'));
    }

    public static function canManage(): bool
    {
        return self::canView() && (bool) auth()->user()?->can('bookings.manage');
    }

    /**
     * Stylists with bookings.own_only only see their own column / bookings.
     * Owners pass every gate, so the restriction is read from the role permissions directly.
     */
    public static function ownOnly(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isOwner()) {
            return false;
        }
        try {
            return $user->hasPermissionTo('bookings.own_only');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Staff id of the signed-in user when restricted (0 = restricted but not linked to a staff profile). */
    public static function ownStaffId(): ?int
    {
        if (! self::ownOnly()) {
            return null;
        }

        return (int) (Staff::withoutGlobalScopes()->where('tenant_id', tenant()?->id)->where('user_id', auth()->id())->value('id') ?? 0);
    }
}
