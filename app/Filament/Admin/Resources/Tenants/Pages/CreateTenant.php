<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Actions\CreateTenant as CreateTenantAction;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Support\Exceptions\DomainException;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateTenant extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = TenantResource::class;

    protected function getSteps(): array
    {
        return [
            Step::make(__('admin.tenant.step_salon'))->icon('heroicon-o-building-storefront')->columns(2)->schema([
                TextInput::make('name')->label(__('fields.salon_name'))->required()->maxLength(120)->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state, Get $get) => $get('slug_touched') ? null : $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->label(__('fields.slug'))->required()->alphaDash()->maxLength(40)
                    ->suffix('.'.config('salon.base_domain'))->unique('tenants', 'slug')
                    ->afterStateUpdated(fn (Set $set) => $set('slug_touched', true)),
                Select::make('locale')->label(__('fields.locale'))->options(config('salon.locales'))->default('en')->required(),
                Select::make('timezone')->label(__('fields.timezone'))->options(collect(['Asia/Qatar', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kuwait', 'Asia/Bahrain', 'Asia/Muscat'])->mapWithKeys(fn ($z) => [$z => $z]))->default('Asia/Qatar')->required(),
                Section::make(__('fields.owner'))->columns(2)->columnSpanFull()->schema([
                    TextInput::make('owner_name')->label(__('fields.owner_name'))->required(),
                    TextInput::make('owner_email')->label(__('fields.owner_email'))->email()->required()->unique('users', 'email'),
                    TextInput::make('owner_phone')->label(__('fields.owner_phone'))->tel()->placeholder('+974 5555 1234'),
                    Toggle::make('send_invite')->label(__('fields.send_invite'))->default(true)->live()->inline(false),
                    TextInput::make('owner_password')->label(__('fields.password'))->password()->revealable()->minLength(10)
                        ->required(fn (Get $get) => ! $get('send_invite'))->visible(fn (Get $get) => ! $get('send_invite')),
                ]),
            ]),
            Step::make(__('admin.tenant.step_plan'))->icon('heroicon-o-sparkles')->columns(3)->schema([
                Select::make('plan_id')->label(__('fields.plan'))->options(fn () => Plan::where('is_active', true)->orderBy('sort')->get()->pluck('name', 'id'))
                    ->required()->live()->afterStateUpdated(fn (Set $set) => $set('modules', [])),
                ToggleButtons::make('cycle_months')->label(__('fields.cycle'))->inline()->required()->live()->default(6)
                    ->options([1 => __('admin.cycle.1'), 3 => __('admin.cycle.3'), 6 => __('admin.cycle.6'), 12 => __('admin.cycle.12')]),
                TextInput::make('branches')->label(__('fields.branches'))->numeric()->minValue(1)->maxValue(100)->default(1)->required()->live(debounce: 400),
                CheckboxList::make('modules')->label(__('admin.tenant.extra_modules'))->columns(3)->columnSpanFull()->live()
                    ->options(fn (Get $get) => TenantResource::moduleOptions($get('plan_id')))
                    ->helperText(fn (Get $get) => $get('plan_id') ? __('admin.tenant.included', ['modules' => collect(Plan::find($get('plan_id'))?->included_modules)->map(fn ($m) => __('salon.modules.'.$m))->join(', ')]) : null),
                TextInput::make('coupon')->label(__('fields.coupon'))->live(onBlur: true)->maxLength(40),
                Toggle::make('trial')->label(__('fields.trial'))->inline(false)->live()->default(false),
                Section::make(__('admin.tenant.quote'))->columnSpanFull()->schema([
                    Text::make(fn (Get $get) => TenantResource::quotePreview($get('plan_id'), $get('cycle_months'), $get('branches'), $get('modules') ?? [], $get('coupon'))),
                ]),
            ]),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateTenantAction::class)->handle([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'locale' => $data['locale'],
                'timezone' => $data['timezone'],
                'owner_name' => $data['owner_name'],
                'owner_email' => $data['owner_email'],
                'owner_phone' => $data['owner_phone'] ?? null,
                'owner_password' => $data['send_invite'] ? null : $data['owner_password'],
            ], [
                'plan_id' => $data['plan_id'],
                'cycle_months' => (int) $data['cycle_months'],
                'branches' => (int) $data['branches'],
                'modules' => $data['modules'] ?? [],
                'coupon' => $data['coupon'] ?? null,
                'trial' => (bool) $data['trial'],
            ], sendInvite: (bool) $data['send_invite']);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.tenant.created', ['salon' => $this->record->name]);
    }
}
