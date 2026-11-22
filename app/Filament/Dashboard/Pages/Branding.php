<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workspace\Actions\GenerateBrandKit;
use App\Domain\Workspace\Actions\UpdateBranding;
use App\Filament\Dashboard\Support\SettingsPage;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Tenant branding (overridable per branch) with a live preview and the brand kit auto-generator. */
class Branding extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'branding';

    /** Brand kit generated from the uploaded logo: ['palette' => [...], 'presets' => [...]] */
    public ?array $kit = null;

    public string $scope = 'tenant';

    public const FONTS_DISPLAY = ['Playfair Display', 'Cormorant Garamond', 'Bodoni Moda', 'DM Serif Display', 'Marcellus', 'Noto Kufi Arabic'];

    public const FONTS_BODY = ['Inter', 'Manrope', 'DM Sans', 'Lato', 'IBM Plex Sans Arabic', 'Tajawal'];

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.branding.title');
    }

    public function getTitle(): string
    {
        return __('workspace.branding.title');
    }

    public function getSubheading(): ?string
    {
        return __('workspace.branding.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('settings.manage') || $user?->can('website.manage'));
    }

    protected function fillData(): array
    {
        return $this->brandingFor($this->scope) + ['scope' => $this->scope];
    }

    protected function brandingFor(string $scope): array
    {
        $tenant = tenant();
        $base = array_replace(Tenant::DEFAULT_BRANDING, ['email_header' => null], $tenant->branding ?? []);
        if ($scope !== 'tenant' && ($branch = Branch::find((int) $scope))) {
            $base = array_replace($base, $branch->branding ?? []);
        }
        $base['about'] = is_array($base['about'] ?? null) ? $base['about'] : ['en' => $base['about'] ?? null, 'ar' => null];
        $base['social'] = (array) ($base['social'] ?? []);

        return $base;
    }

    public function form(Schema $schema): Schema
    {
        $disk = fn () => 'branding/'.tenant()->id;
        $upload = fn (string $name, string $label) => FileUpload::make($name)->label($label)->image()->disk('public')->directory($disk)->maxSize(4096)->live();

        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'xl' => 2])->schema([
                Section::make(__('workspace.branding.scope'))->description(__('workspace.branding.scope_help'))->compact()->schema([
                    Select::make('scope')->hiddenLabel()->native(false)->live()
                        ->options(fn () => ['tenant' => __('workspace.branding.scope_tenant')] + Branch::query()->orderBy('sort')->get()
                            ->mapWithKeys(fn ($b) => [(string) $b->id => __('workspace.branding.scope_branch', ['branch' => $b->name])])->all())
                        ->afterStateUpdated(function ($state) {
                            $this->scope = (string) ($state ?: 'tenant');
                            $this->form->fill($this->fillData());
                        }),
                ]),
                Section::make(__('workspace.branding.identity'))->description(__('workspace.branding.identity_help'))->columns(2)
                    ->key('identity')->headerActions([$this->generateKitAction()])
                    ->schema([
                        $upload('logo_path', __('workspace.branding.logo_light')),
                        $upload('logo_dark_path', __('workspace.branding.logo_dark')),
                        $upload('favicon_path', __('workspace.branding.favicon')),
                        $upload('hero_path', __('workspace.branding.hero')),
                    ]),
                Section::make(__('workspace.branding.palette'))->columns(['default' => 2, 'md' => 4])->schema([
                    ColorPicker::make('primary')->label(__('workspace.branding.primary'))->required()->live(debounce: 300),
                    ColorPicker::make('accent')->label(__('workspace.branding.accent'))->live(debounce: 300),
                    ColorPicker::make('ink')->label(__('workspace.branding.ink'))->live(debounce: 300),
                    ColorPicker::make('background')->label(__('workspace.branding.background'))->live(debounce: 300),
                    View::make('filament.dashboard.branding.kit')->columnSpanFull()->visible(fn () => $this->kit !== null),
                ]),
                Section::make(__('workspace.branding.typography'))->columns(3)->schema([
                    Select::make('font_display')->label(__('workspace.branding.font_display'))->options(array_combine(self::FONTS_DISPLAY, self::FONTS_DISPLAY))->native(false)->live(),
                    Select::make('font_body')->label(__('workspace.branding.font_body'))->options(array_combine(self::FONTS_BODY, self::FONTS_BODY))->native(false)->live(),
                    ToggleButtons::make('button_style')->label(__('workspace.branding.button_style'))->inline()->live()
                        ->options(['pill' => __('workspace.branding.pill'), 'rounded' => __('workspace.branding.rounded')]),
                ]),
                Section::make(__('workspace.branding.content'))->columns(2)->collapsible()->schema([
                    Textarea::make('about.en')->label(__('workspace.branding.about').' · English')->rows(4)->live(onBlur: true),
                    Textarea::make('about.ar')->label(__('workspace.branding.about').' · العربية')->rows(4)->extraInputAttributes(['dir' => 'rtl']),
                    Textarea::make('receipt_footer')->label(__('workspace.branding.receipt_footer'))->rows(2)->placeholder(__('workspace.branding.receipt_placeholder'))->live(onBlur: true),
                    TextInput::make('email_header')->label(__('workspace.branding.email_header'))->placeholder(__('workspace.branding.email_header_placeholder'))->maxLength(120),
                ]),
                Section::make(__('workspace.branding.social'))->columns(2)->collapsible()->collapsed()->schema(
                    collect(UpdateBranding::SOCIALS)->map(fn ($s) => TextInput::make('social.'.$s)->label(__('workspace.branding.socials.'.$s))
                        ->prefixIcon($s === 'whatsapp' ? Heroicon::OutlinedChatBubbleLeftRight : Heroicon::OutlinedLink)
                        ->placeholder($s === 'whatsapp' ? '+974 5555 0000' : 'https://'))->all()
                ),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'xl' => 1])->extraAttributes(['class' => 'xl:sticky xl:top-20 self-start'])->schema([
                View::make('filament.dashboard.branding.preview')->viewData(fn (Get $get) => ['b' => static::previewData($get), 'tenant' => tenant()]),
            ]),
        ]);
    }

    /** Normalised preview values, resolving uploads that are not saved yet. */
    public static function previewData(Get $get): array
    {
        $url = function ($state) {
            $value = is_array($state) ? (array_values($state)[0] ?? null) : $state;
            if ($value instanceof TemporaryUploadedFile) {
                try {
                    return $value->temporaryUrl();
                } catch (\Throwable) {
                    return null;
                }
            }

            return filled($value) ? Storage::disk('public')->url($value) : null;
        };

        return [
            'primary' => $get('primary') ?: '#C8A96A',
            'accent' => $get('accent') ?: '#E9D6CF',
            'ink' => $get('ink') ?: '#1C1A17',
            'background' => $get('background') ?: '#FAF7F2',
            'font_display' => $get('font_display') ?: 'Playfair Display',
            'font_body' => $get('font_body') ?: 'Inter',
            'radius' => $get('button_style') === 'rounded' ? '12px' : '999px',
            'logo' => $url($get('logo_path')),
            'hero' => $url($get('hero_path')),
            'about' => $get('about.en'),
            'receipt_footer' => $get('receipt_footer'),
        ];
    }

    protected function generateKitAction(): Action
    {
        return Action::make('generateKit')->label(__('workspace.branding.generate_kit'))->icon(Heroicon::OutlinedSparkles)->color('primary')->link()
            ->action(function () {
                $path = $this->logoAbsolutePath();
                if (! $path) {
                    Notification::make()->warning()->title(__('workspace.branding.upload_logo_first'))->send();

                    return;
                }
                try {
                    $this->kit = app(GenerateBrandKit::class)->handle($path);
                    Notification::make()->success()->title(__('workspace.branding.kit_ready'))->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    protected function logoAbsolutePath(): ?string
    {
        $state = $this->data['logo_path'] ?? null;
        $value = is_array($state) ? (array_values($state)[0] ?? null) : $state;
        if ($value instanceof TemporaryUploadedFile) {
            return $value->getRealPath();
        }
        if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
            return TemporaryUploadedFile::unserializeFromLivewireRequest($value)?->getRealPath();
        }

        return filled($value) && Storage::disk('public')->exists($value) ? Storage::disk('public')->path($value) : null;
    }

    public function applyPreset(int $index): void
    {
        $preset = $this->kit['presets'][$index] ?? null;
        if (! $preset) {
            return;
        }
        foreach (['primary', 'accent', 'ink', 'background', 'font_display', 'font_body', 'button_style'] as $key) {
            $this->data[$key] = $preset[$key];
        }
        Notification::make()->success()->title(__('workspace.branding.preset_applied', ['name' => $preset['name']]))->body(__('workspace.branding.preset_save_hint'))->send();
    }

    public function save(): void
    {
        abort_if($this->isReadOnly() || ! static::canAccess(), 403);
        $data = $this->form->getState();
        $scope = $data['scope'] ?? 'tenant';
        unset($data['scope']);

        try {
            app(UpdateBranding::class)->handle(tenant(), $data, $scope === 'tenant' ? null : Branch::findOrFail((int) $scope));
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('workspace.branding.saved'))->send();
    }
}
