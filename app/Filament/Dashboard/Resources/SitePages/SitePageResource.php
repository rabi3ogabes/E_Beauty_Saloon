<?php

namespace App\Filament\Dashboard\Resources\SitePages;

use App\Domain\Websites\Models\SitePage;
use App\Domain\Websites\Support\SectionRegistry;
use App\Filament\Dashboard\Resources\SitePages\Pages\CreateSitePage;
use App\Filament\Dashboard\Resources\SitePages\Pages\EditSitePage;
use App\Filament\Dashboard\Resources\SitePages\Pages\ListSitePages;
use App\Filament\Support\Translatable;
use App\Filament\Support\Uploads;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Salon website pages built from ordered, bilingual sections. */
class SitePageResource extends Resource
{
    protected static ?string $model = SitePage::class;

    protected static ?string $slug = 'website/pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.website');
    }

    public static function getModelLabel(): string
    {
        return __('website.dashboard.page');
    }

    public static function getPluralModelLabel(): string
    {
        return __('website.dashboard.pages');
    }

    public static function canViewAny(): bool
    {
        return (bool) (tenant()?->hasModule('website') && auth()->user()?->can('website.manage'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(2)->schema([
                Translatable::make('title', __('fields.title')),
                TextInput::make('slug')->label(__('fields.slug'))->required()->alphaDash()->maxLength(60)
                    ->disabled(fn ($record) => (bool) $record?->is_home)->dehydrated()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
            ]),
            Section::make(__('website.dashboard.seo'))->columnSpan(1)->schema([
                Translatable::make('seo_title', __('website.dashboard.seo_title'), required: false),
                Translatable::make('seo_description', __('website.dashboard.seo_description'), required: false, textarea: true, rows: 2),
                FileUpload::make('og_image_path')->label(__('website.dashboard.og_image'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory(fn () => 'tenants/'.tenant()?->id.'/site')->maxSize(4096),
                Toggle::make('is_published')->label(__('website.dashboard.published'))->default(true),
                Toggle::make('in_menu')->label(__('website.dashboard.in_menu'))->hidden(fn ($record) => (bool) $record?->is_home),
            ]),
            Repeater::make('sections')->label(__('website.dashboard.sections'))->relationship()->orderColumn('sort')->columnSpanFull()
                ->collapsible()->collapsed()->cloneable()->reorderableWithButtons()
                ->itemLabel(fn (array $state) => (($state['is_visible'] ?? true) ? '' : '◌ ').(SectionRegistry::options()[$state['type'] ?? ''] ?? __('website.dashboard.section_type'))
                    .(filled(data_get($state, 'content.en.title')) ? ' — '.Str::limit(data_get($state, 'content.en.title'), 40) : ''))
                ->schema(static::sectionSchema()),
        ]);
    }

    /** Per-type fields: bilingual copy in content.{en,ar}.*, shared options in settings.*. */
    public static function sectionSchema(): array
    {
        $types = SectionRegistry::TYPES;
        $has = fn (string $field) => fn ($get) => in_array($field, $types[$get('type')]['fields'] ?? [], true);
        $is = fn (string ...$t) => fn ($get) => in_array($get('type'), $t, true);
        $copy = function (string $locale) use ($has) {
            $rtl = $locale === 'ar' ? ['dir' => 'rtl', 'lang' => 'ar'] : [];

            return [
                TextInput::make("content.$locale.eyebrow")->label(__('fields.title').' (eyebrow)')->visible($has('eyebrow'))->extraInputAttributes($rtl),
                TextInput::make("content.$locale.title")->label(__('fields.title'))->visible($has('title'))->extraInputAttributes($rtl),
                Textarea::make("content.$locale.subtitle")->label(__('fields.description'))->rows(2)->visible($has('subtitle'))->extraInputAttributes($rtl),
                Textarea::make("content.$locale.body")->label(__('fields.description'))->rows(4)->visible($has('body'))->extraInputAttributes($rtl),
                TextInput::make("content.$locale.cta_label")->label('CTA')->visible($has('cta_label'))->extraInputAttributes($rtl),
                Repeater::make("content.$locale.items")->label(__('website.dashboard.faq_items'))->visible(fn ($get) => $get('type') === 'faq')
                    ->schema([
                        TextInput::make('q')->label(__('website.dashboard.question'))->required()->extraInputAttributes($rtl),
                        Textarea::make('a')->label(__('website.dashboard.answer'))->rows(2)->required()->extraInputAttributes($rtl),
                    ])->collapsible()->defaultItems(0),
            ];
        };

        return [
            Grid::make(3)->schema([
                Select::make('type')->label(__('website.dashboard.section_type'))->options(SectionRegistry::options())->required()->live()->columnSpan(2),
                Toggle::make('is_visible')->label(__('website.dashboard.visible'))->default(true)->inline(false),
            ]),
            Tabs::make('copy')->tabs([
                Tabs\Tab::make(__('website.dashboard.content_en'))->schema($copy('en')),
                Tabs\Tab::make(__('website.dashboard.content_ar'))->schema($copy('ar')),
            ])->visible(fn ($get) => filled($get('type'))),
            FileUpload::make('settings.image')->label(__('website.dashboard.hero'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory(fn () => 'tenants/'.tenant()?->id.'/site')->maxSize(6144)->visible($is('hero')),
            FileUpload::make('settings.images')->label(__('website.dashboard.images'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->multiple()->reorderable()->maxFiles(12)->disk('public')
                ->directory(fn () => 'tenants/'.tenant()?->id.'/gallery')->maxSize(6144)->visible($is('gallery')),
            Repeater::make('settings.before_after')->label(__('website.dashboard.before_after'))->visible($is('gallery'))->columns(2)->defaultItems(0)->schema([
                FileUpload::make('before')->label(__('website.dashboard.before'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory(fn () => 'tenants/'.tenant()?->id.'/gallery'),
                FileUpload::make('after')->label(__('website.dashboard.after'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory(fn () => 'tenants/'.tenant()?->id.'/gallery'),
            ]),
            TextInput::make('settings.limit')->label(__('website.dashboard.limit'))->numeric()->minValue(1)->maxValue(24)->visible($is('services', 'team', 'shop')),
            TextInput::make('settings.handle')->label(__('website.dashboard.instagram_handle'))->prefix('@')->visible($is('instagram')),
            TagsInput::make('settings.urls')->label(__('website.dashboard.instagram_urls'))->placeholder('https://www.instagram.com/p/…')->visible($is('instagram')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('title')->label(__('fields.title'))->weight('semibold')->description(fn ($record) => $record->is_home ? '/' : '/pages/'.$record->slug),
            IconColumn::make('is_home')->label(__('website.dashboard.is_home'))->boolean(),
            IconColumn::make('is_published')->label(__('website.dashboard.published'))->boolean(),
            TextColumn::make('sections_count')->counts('sections')->label(__('website.dashboard.sections')),
            TextColumn::make('updated_at')->label(__('salon.ui.created'))->since(),
        ])->recordActions([
            Action::make('view')->label(__('website.dashboard.preview'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn ($record) => url($record->is_home ? '/' : '/pages/'.$record->slug), shouldOpenInNewTab: true),
            EditAction::make(),
            DeleteAction::make()->hidden(fn ($record) => $record->is_home),
        ])->emptyStateHeading(__('website.dashboard.empty_pages'))->emptyStateDescription(__('website.dashboard.empty_pages_body'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSitePages::route('/'),
            'create' => CreateSitePage::route('/create'),
            'edit' => EditSitePage::route('/{record}/edit'),
        ];
    }
}
