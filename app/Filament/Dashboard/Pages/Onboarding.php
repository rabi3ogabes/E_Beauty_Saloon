<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Branches\Actions\NormalizeOpeningHours;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Templates\ServiceTemplates;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Workspace\Actions\CompleteOnboarding;
use App\Domain\Workspace\Actions\SavePaymentGateway;
use App\Filament\Dashboard\Resources\Branches\BranchResource;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * First-login onboarding for the owner: business -> first branch -> branding -> services -> payment -> done.
 *
 * @property-read Schema $form
 */
class Onboarding extends Page
{
    protected static ?string $slug = 'welcome';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.dashboard.pages.onboarding';

    public ?array $data = [];

    public bool $completed = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    public function getTitle(): string
    {
        return $this->completed ? '' : __('workspace.onboarding.title');
    }

    public function getSubheading(): ?string
    {
        return $this->completed ? null : __('workspace.onboarding.subheading');
    }

    public function mount(): void
    {
        $tenant = tenant();
        $branch = Branch::query()->withoutGlobalScope('allowed_branches')->orderBy('id')->first();

        $this->form->fill([
            'business' => ['name' => $tenant->name, 'legal_name' => $tenant->legal_name, 'phone' => $tenant->phone, 'email' => $tenant->email,
                'tax_number' => $tenant->tax_number, 'default_locale' => $tenant->default_locale ?? 'en'],
            'branch' => [
                'name' => ['en' => $branch?->getTranslation('name', 'en', false) ?: __('workspace.onboarding.default_branch'), 'ar' => $branch?->getTranslation('name', 'ar', false)],
                'address' => ['en' => $branch?->getTranslation('address', 'en', false), 'ar' => $branch?->getTranslation('address', 'ar', false)],
                'city' => $branch?->city ?? 'Doha', 'area' => $branch?->area, 'lat' => $branch?->lat, 'lng' => $branch?->lng,
                'phone' => $branch?->phone ?? $tenant->phone, 'whatsapp' => $branch?->whatsapp,
            ],
            'hours' => NormalizeOpeningHours::toRows($branch?->opening_hours),
            'primary' => $tenant->brand('primary'), 'accent' => $tenant->brand('accent'),
            'font_display' => $tenant->brand('font_display'), 'font_body' => $tenant->brand('font_body'), 'button_style' => $tenant->brand('button_style'),
            'logo_path' => $tenant->brand('logo_path'),
            'templates' => ['hair', 'nails'],
            'gateway' => ['driver' => null, 'mode' => 'test', 'credentials' => []],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make(__('workspace.onboarding.step_business'))->icon(Heroicon::OutlinedBuildingStorefront)
                    ->description(__('workspace.onboarding.step_business_help'))->columns(2)->schema([
                        TextInput::make('business.name')->label(__('fields.salon_name'))->required()->maxLength(120),
                        TextInput::make('business.legal_name')->label(__('workspace.onboarding.legal_name'))->maxLength(160),
                        TextInput::make('business.phone')->label(__('fields.phone'))->tel()->maxLength(20),
                        TextInput::make('business.email')->label(__('fields.email'))->email(),
                        TextInput::make('business.tax_number')->label(__('workspace.onboarding.tax_number')),
                        Select::make('business.default_locale')->label(__('workspace.onboarding.website_language'))->options(config('salon.locales'))->native(false)->required(),
                    ]),
                Step::make(__('workspace.onboarding.step_branch'))->icon(Heroicon::OutlinedMapPin)
                    ->description(__('workspace.onboarding.step_branch_help'))->columns(2)->schema([
                        TextInput::make('branch.name.en')->label(__('fields.name').' · English')->required()->maxLength(120),
                        TextInput::make('branch.name.ar')->label(__('fields.name').' · العربية')->extraInputAttributes(['dir' => 'rtl'])->maxLength(120),
                        Textarea::make('branch.address.en')->label(__('fields.address').' · English')->rows(2),
                        Textarea::make('branch.address.ar')->label(__('fields.address').' · العربية')->rows(2)->extraInputAttributes(['dir' => 'rtl']),
                        TextInput::make('branch.city')->label(__('workspace.branches.city')),
                        TextInput::make('branch.area')->label(__('workspace.branches.area')),
                        TextInput::make('branch.lat')->label(__('workspace.branches.lat'))->numeric()->minValue(-90)->maxValue(90)->live(onBlur: true),
                        TextInput::make('branch.lng')->label(__('workspace.branches.lng'))->numeric()->minValue(-180)->maxValue(180)->live(onBlur: true),
                        Text::make(fn (Get $get) => BranchResource::mapPreview($get('branch.lat'), $get('branch.lng')))->columnSpanFull(),
                        Section::make(__('workspace.branches.hours'))->description(__('workspace.branches.hours_help'))->collapsible()->collapsed()->columnSpanFull()
                            ->schema([BranchResource::hoursRepeater('hours')]),
                    ]),
                Step::make(__('workspace.onboarding.step_branding'))->icon(Heroicon::OutlinedPaintBrush)
                    ->description(__('workspace.onboarding.step_branding_help'))->schema([
                        Grid::make(['default' => 1, 'lg' => 2])->schema([
                            Grid::make(2)->schema([
                                FileUpload::make('logo_path')->label(__('workspace.branding.logo_light'))->image()->disk('public')->directory(fn () => 'branding/'.tenant()->id)->maxSize(4096)->live()->columnSpanFull(),
                                ColorPicker::make('primary')->label(__('workspace.branding.primary'))->required()->live(debounce: 300),
                                ColorPicker::make('accent')->label(__('workspace.branding.accent'))->live(debounce: 300),
                                Select::make('font_display')->label(__('workspace.branding.font_display'))->options(array_combine(Branding::FONTS_DISPLAY, Branding::FONTS_DISPLAY))->native(false)->live(),
                                Select::make('font_body')->label(__('workspace.branding.font_body'))->options(array_combine(Branding::FONTS_BODY, Branding::FONTS_BODY))->native(false)->live(),
                                ToggleButtons::make('button_style')->label(__('workspace.branding.button_style'))->inline()->live()->columnSpanFull()
                                    ->options(['pill' => __('workspace.branding.pill'), 'rounded' => __('workspace.branding.rounded')]),
                            ]),
                            View::make('filament.dashboard.branding.preview')->viewData(fn (Get $get) => ['b' => Branding::previewData($get), 'tenant' => tenant()]),
                        ]),
                    ]),
                Step::make(__('workspace.onboarding.step_services'))->icon(Heroicon::OutlinedSparkles)
                    ->description(__('workspace.onboarding.step_services_help'))->schema([
                        CheckboxList::make('templates')->hiddenLabel()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->bulkToggleable()
                            ->options(collect(ServiceTemplates::CATEGORIES)->mapWithKeys(fn ($c, $k) => [$k => app()->getLocale() === 'ar' ? $c['ar'] : $c['en']]))
                            ->descriptions(collect(ServiceTemplates::all())->map(fn ($items) => new HtmlString(e(trans_choice('workspace.onboarding.services_count', count($items), ['count' => count($items)]))
                                .' · '.e(collect($items)->take(3)->map(fn ($i) => (app()->getLocale() === 'ar' ? $i['ar'] : $i['en']).' '.money($i['price']))->join(', ')).'…'))->all()),
                        Text::make(__('workspace.onboarding.services_note'))->color('gray'),
                    ]),
                Step::make(__('workspace.onboarding.step_payment'))->icon(Heroicon::OutlinedCreditCard)
                    ->description(__('workspace.onboarding.step_payment_help'))->columns(2)->schema([
                        Select::make('gateway.driver')->label(__('workspace.payments.driver'))->native(false)->live()->placeholder(__('workspace.onboarding.skip_payment'))
                            ->options(collect(TenantPaymentGateway::DRIVERS)->mapWithKeys(fn ($d) => [$d => __('salon.payments.drivers.'.$d)])),
                        ToggleButtons::make('gateway.mode')->label(__('workspace.payments.mode'))->inline()
                            ->options(['test' => __('workspace.payments.test'), 'live' => __('workspace.payments.live')])
                            ->visible(fn (Get $get) => in_array($get('gateway.driver'), TenantPaymentGateway::ONLINE_DRIVERS, true)),
                        ...collect(SavePaymentGateway::DRIVER_FIELDS)->flatten()->unique()->map(fn ($field) => TextInput::make('gateway.credentials.'.$field)
                            ->label(__('workspace.payments.fields.'.$field))->password($secret = ! in_array($field, SavePaymentGateway::PUBLIC_FIELDS, true))->revealable($secret)
                            ->visible(fn (Get $get) => in_array($field, SavePaymentGateway::DRIVER_FIELDS[$get('gateway.driver')] ?? [], true)))->values()->all(),
                        Text::make(__('workspace.payments.direct_copy'))->columnSpanFull()->color('gray'),
                    ]),
            ])->submitAction(Action::make('complete')->label(__('workspace.onboarding.finish'))->icon(Heroicon::OutlinedCheck)->submit('complete'))
                ->skippable(false),
        ]);
    }

    public function complete(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();

        $logo = $data['logo_path'] ?? null;
        $payload = [
            'business' => $data['business'] ?? [],
            'branch' => ($data['branch'] ?? []) + ['opening_hours' => app(NormalizeOpeningHours::class)->handle($data['hours'] ?? [])],
            'branding' => [
                'primary' => $data['primary'] ?? null, 'accent' => $data['accent'] ?? null,
                'font_display' => $data['font_display'] ?? null, 'font_body' => $data['font_body'] ?? null,
                'button_style' => $data['button_style'] ?? null, 'logo_path' => is_string($logo) ? $logo : null,
            ],
            'templates' => $data['templates'] ?? [],
            'gateway' => filled($data['gateway']['driver'] ?? null) ? $data['gateway'] : null,
        ];

        try {
            app(CompleteOnboarding::class)->handle(tenant(), $payload);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->completed = true;
    }

    public function getSalonUrl(): string
    {
        return tenant()->fresh()->url();
    }

    public static function logoUrl(mixed $state): ?string
    {
        $value = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        return $value instanceof TemporaryUploadedFile ? null : (filled($value) ? Storage::disk('public')->url($value) : null);
    }
}
