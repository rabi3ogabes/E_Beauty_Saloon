<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('admin.dashboard.greeting', ['name' => explode(' ', (string) auth()->user()?->name)[0]]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2];
    }
}
