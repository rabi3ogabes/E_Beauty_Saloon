<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('admin.tenant.new'))->icon('heroicon-o-plus')];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('admin.all'))];
        foreach ([TenantStatus::Active, TenantStatus::Trial, TenantStatus::Grace, TenantStatus::Suspended] as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status))
                ->badge(fn () => Tenant::where('status', $status)->count() ?: null);
        }

        return $tabs;
    }
}
