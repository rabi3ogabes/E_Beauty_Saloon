<?php

namespace App\Filament\Pos\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** POS home — replaced by the checkout terminal page (Phase 4). */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('salon.nav.sales');
    }
}
