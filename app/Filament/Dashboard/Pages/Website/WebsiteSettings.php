<?php

namespace App\Filament\Dashboard\Pages\Website;

use App\Domain\Websites\Models\PushSubscription;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** SEO defaults, analytics / pixel IDs (validated, never raw HTML), Google Business Profile link, PWA info. */
class WebsiteSettings extends Page
{
    protected static ?string $slug = 'website/settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.dashboard.website.settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.website');
    }

    public static function getNavigationLabel(): string
    {
        return __('website.dashboard.settings');
    }

    public function getTitle(): string
    {
        return __('website.dashboard.settings');
    }

    public static function canAccess(): bool
    {
        return (bool) (tenant()?->hasModule('website') && auth()->user()?->can('website.manage'));
    }

    public function mount(): void
    {
        $this->form->fill((array) (tenant()->setting('website') ?? []));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('website.dashboard.seo'))->schema([
                Textarea::make('seo_description')->label(__('website.dashboard.seo_description'))->rows(2)->maxLength(160),
            ]),
            Section::make(__('website.dashboard.analytics'))->columns(3)->schema([
                TextInput::make('ga4_id')->label(__('website.dashboard.ga4'))->placeholder('G-XXXXXXXXXX')->regex('/^G-[A-Z0-9]{4,16}$/'),
                TextInput::make('meta_pixel_id')->label(__('website.dashboard.meta_pixel'))->placeholder('123456789012345')->regex('/^\d{6,20}$/'),
                TextInput::make('tiktok_pixel_id')->label(__('website.dashboard.tiktok_pixel'))->placeholder('C1A2B3C4D5E6F7G8')->regex('/^[A-Z0-9]{8,30}$/'),
            ]),
            Section::make('Google')->schema([
                TextInput::make('gbp_booking_url')->label(__('website.dashboard.gbp'))->url()->startsWith(['https://'])->maxLength(500),
            ]),
            Section::make(__('website.dashboard.push'))->schema([
                Text::make(fn () => __('website.dashboard.push_help', ['count' => PushSubscription::where('tenant_id', tenant()->id)->count()])),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if(tenant()->isReadOnly(), 423);
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['website'] = array_merge($settings['website'] ?? [], collect($this->form->getState())->map(fn ($v) => is_string($v) ? trim($v) : $v)->all());
        $tenant->update(['settings' => $settings]);
        activity()->performedOn($tenant)->log('website.settings.updated');
        Notification::make()->success()->title(__('website.dashboard.saved'))->send();
    }
}
