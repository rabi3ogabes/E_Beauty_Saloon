<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Domain\Billing\Actions\ChangeSubscription;
use App\Domain\Billing\Models\Plan;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TenantResource::impersonateAction(),
            Action::make('changePlan')->label(__('admin.tenant.change_plan'))->icon('heroicon-o-arrows-up-down')->color('gray')
                ->visible(fn () => $this->record->currentSubscription !== null && auth()->user()->can('platform.billing.manage'))
                ->fillForm(fn () => [
                    'plan_id' => $this->record->currentSubscription->plan_id,
                    'branches' => $this->record->currentSubscription->branches_count,
                    'modules' => array_values(array_diff($this->record->currentSubscription->modules, $this->record->currentSubscription->plan->included_modules ?? [])),
                ])
                ->schema([
                    Select::make('plan_id')->label(__('fields.plan'))->options(fn () => Plan::where('is_active', true)->get()->pluck('name', 'id'))->required()->live(),
                    TextInput::make('branches')->label(__('fields.branches'))->numeric()->minValue(1)->required()->live(debounce: 400),
                    CheckboxList::make('modules')->label(__('admin.tenant.extra_modules'))->columns(2)->live()
                        ->options(fn (Get $get) => TenantResource::moduleOptions($get('plan_id'))),
                    Text::make(function (Get $get) {
                        if (! $get('plan_id')) {
                            return '';
                        }
                        $preview = app(ChangeSubscription::class)->preview($this->record->currentSubscription, Plan::find($get('plan_id')), max(1, (int) $get('branches')), $get('modules') ?? []);

                        return $preview['prorated'] > 0
                            ? __('admin.tenant.prorated_charge', ['amount' => money($preview['prorated'], $this->record->currency)])
                            : __('admin.tenant.prorated_credit', ['amount' => money(abs($preview['prorated']), $this->record->currency)]);
                    }),
                ])
                ->action(function (array $data) {
                    $invoice = app(ChangeSubscription::class)->handle($this->record->currentSubscription, Plan::find($data['plan_id']), (int) $data['branches'], $data['modules'] ?? []);
                    Notification::make()->success()
                        ->title($invoice ? __('admin.tenant.proration_invoiced', ['number' => $invoice->number]) : __('admin.tenant.plan_changed'))->send();
                    $this->refreshFormData([]);
                }),
            EditAction::make()->color('gray'),
            ActionGroup::make(TenantResource::lifecycleActions()),
        ];
    }
}
