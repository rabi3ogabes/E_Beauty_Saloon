<?php

namespace App\Filament\Dashboard\Pages\Website;

use App\Domain\Websites\Actions\UpdateSiteDesign;
use App\Domain\Websites\Support\ThemeRegistry;
use App\Filament\Support\Uploads;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Theme, colours, fonts and imagery — live on the salon website instantly. */
class WebsiteDesign extends Page
{
    protected static ?string $slug = 'website/design';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.dashboard.website.design';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.website');
    }

    public static function getNavigationLabel(): string
    {
        return __('website.dashboard.design');
    }

    public function getTitle(): string
    {
        return __('website.dashboard.design');
    }

    public static function canAccess(): bool
    {
        return (bool) (tenant()?->hasModule('website') && auth()->user()?->can('website.manage'));
    }

    public function mount(): void
    {
        $t = tenant();
        $this->form->fill(collect(['theme', 'primary', 'accent', 'ink', 'background', 'font_display', 'font_body', 'button_style', 'logo_path', 'logo_dark_path', 'favicon_path', 'hero_path', 'about', 'social'])
            ->mapWithKeys(fn ($k) => [$k => $t->brand($k)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $fonts = array_combine(UpdateSiteDesign::FONTS, UpdateSiteDesign::FONTS);
        $dir = fn () => 'tenants/'.tenant()?->id.'/brand';

        return $schema->statePath('data')->components([
            Section::make(__('website.dashboard.theme'))->description(__('website.dashboard.theme_help'))->schema([
                Radio::make('theme')->hiddenLabel()->required()->live()->columns(['default' => 1, 'md' => 5])
                    ->options(ThemeRegistry::options())
                    ->descriptions(collect(ThemeRegistry::THEMES)->map(fn ($t) => $t['description'][app()->getLocale()] ?? $t['description']['en'])->all())
                    ->afterStateUpdated(function ($state, $set) {
                        $theme = ThemeRegistry::THEMES[$state] ?? null;
                        if ($theme) {
                            foreach (['primary', 'accent', 'ink', 'background', 'font_display', 'font_body'] as $k) {
                                $set($k, $theme[$k]);
                            }
                        }
                    }),
            ]),
            Section::make(__('website.dashboard.colors'))->columns(4)->schema([
                ColorPicker::make('primary')->label(__('website.dashboard.primary'))->regex('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i')->required(),
                ColorPicker::make('accent')->label(__('website.dashboard.accent'))->regex('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i')->required(),
                ColorPicker::make('ink')->label(__('website.dashboard.ink'))->regex('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i')->required(),
                ColorPicker::make('background')->label(__('website.dashboard.background'))->regex('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i')->required(),
            ]),
            Section::make(__('website.dashboard.fonts'))->columns(3)->schema([
                Select::make('font_display')->label(__('website.dashboard.font_display'))->options($fonts)->required(),
                Select::make('font_body')->label(__('website.dashboard.font_body'))->options($fonts)->required(),
                Select::make('button_style')->label(__('website.dashboard.button_style'))->required()
                    ->options(['pill' => __('website.dashboard.pill'), 'rounded' => __('website.dashboard.rounded'), 'square' => __('website.dashboard.square')]),
            ]),
            Section::make(__('website.dashboard.images'))->columns(2)->schema([
                FileUpload::make('logo_path')->label(__('website.dashboard.logo'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory($dir)->maxSize(2048),
                FileUpload::make('logo_dark_path')->label(__('website.dashboard.logo_dark'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory($dir)->maxSize(2048),
                FileUpload::make('hero_path')->label(__('website.dashboard.hero'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->imageEditor()->disk('public')->directory($dir)->maxSize(8192),
                FileUpload::make('favicon_path')->label(__('website.dashboard.favicon'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory($dir)->maxSize(512),
                Textarea::make('about')->label(__('website.dashboard.about'))->rows(3)->maxLength(600)->columnSpanFull(),
                KeyValue::make('social')->label(__('website.dashboard.social'))->keyLabel('Network')->valueLabel('URL')->columnSpanFull(),
            ]),
        ]);
    }

    public function save(UpdateSiteDesign $update): void
    {
        $data = $this->form->getState();
        $data['social'] = collect($data['social'] ?? [])->filter(fn ($url) => is_string($url) && preg_match('#^https://#', $url))->all();
        try {
            $update->handle(tenant(), $data);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(__('website.dashboard.saved'))->send();
    }
}
