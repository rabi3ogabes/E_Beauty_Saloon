<?php

namespace App\Filament\Admin\Resources\PlatformPages;

use App\Domain\Websites\Models\PlatformPage;
use App\Domain\Websites\Support\PlatformContent;
use App\Filament\Admin\Resources\PlatformPages\Pages\CreatePlatformPage;
use App\Filament\Admin\Resources\PlatformPages\Pages\EditPlatformPage;
use App\Filament\Admin\Resources\PlatformPages\Pages\ListPlatformPages;
use App\Filament\Support\Translatable;
use App\Filament\Support\Uploads;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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

/** Platform marketing site CMS: section-based pages (hero, features, pricing, FAQ, CTA, testimonials, video...). */
class PlatformPageResource extends Resource
{
    protected static ?string $model = PlatformPage::class;

    protected static ?string $slug = 'website-pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.website');
    }

    public static function getModelLabel(): string
    {
        return __('website.admin.page');
    }

    public static function getPluralModelLabel(): string
    {
        return __('website.admin.pages');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.cms.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(2)->schema([
                Translatable::make('title', __('fields.title')),
                TextInput::make('slug')->label(__('fields.slug'))->required()->alphaDash()->unique(ignoreRecord: true)
                    ->helperText('home · pricing · help · legal-terms · legal-privacy · features-{module} · any custom slug (/p/{slug})'),
            ]),
            Section::make(__('website.dashboard.seo'))->columnSpan(1)->schema([
                Translatable::make('seo_title', __('website.dashboard.seo_title'), required: false),
                Translatable::make('seo_description', __('website.dashboard.seo_description'), required: false, textarea: true, rows: 2),
                FileUpload::make('og_image_path')->label(__('website.dashboard.og_image'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory('platform/og')->maxSize(4096),
                Toggle::make('is_published')->label(__('website.dashboard.published'))->default(true),
                Toggle::make('in_footer')->label(__('website.admin.in_footer')),
            ]),
            Builder::make('sections')->label(__('website.admin.blocks'))->columnSpanFull()->collapsible()->cloneable()->reorderableWithButtons()
                ->blocks(static::blocks()),
        ]);
    }

    /** @return list<Builder\Block> */
    public static function blocks(): array
    {
        $copy = function (array $fields, array $lists = []) {
            $tab = function (string $locale) use ($fields, $lists) {
                $attrs = $locale === 'ar' ? ['dir' => 'rtl', 'lang' => 'ar'] : [];
                $schema = [];
                foreach ($fields as $field => $type) {
                    $schema[] = ($type === 'textarea' ? Textarea::make("$locale.$field")->rows(3) : TextInput::make("$locale.$field"))
                        ->label(ucfirst(str_replace('_', ' ', $field)))->extraInputAttributes($attrs);
                }
                foreach ($lists as $list => $itemFields) {
                    $schema[] = Repeater::make("$locale.$list")->label(ucfirst($list))->collapsible()->defaultItems(0)->schema(
                        collect($itemFields)->map(fn ($t, $f) => ($t === 'textarea' ? Textarea::make($f)->rows(2) : TextInput::make($f))->label(ucfirst($f))->extraInputAttributes($attrs))->values()->all()
                    );
                }

                return $schema;
            };

            return Tabs::make()->tabs([Tabs\Tab::make('English')->schema($tab('en')), Tabs\Tab::make('العربية')->schema($tab('ar'))]);
        };
        $label = fn (string $type) => __('website.platform_blocks.'.$type);

        return [
            Builder\Block::make('hero')->label($label('hero'))->schema([
                $copy(['eyebrow' => 'text', 'title' => 'text', 'body' => 'textarea', 'cta_label' => 'text', 'cta_url' => 'text', 'secondary_label' => 'text', 'secondary_url' => 'text']),
                Grid::make(2)->schema([
                    TextInput::make('video_url')->label('Video URL (mp4)')->url(),
                    FileUpload::make('image')->label('Poster image')->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->disk('public')->directory('platform/hero'),
                ]),
            ]),
            Builder\Block::make('logos')->label($label('logos'))->schema([$copy(['title' => 'text']), TagsInput::make('names')->label('Names')]),
            Builder\Block::make('modules')->label($label('modules'))->schema([$copy(['title' => 'text', 'subtitle' => 'textarea'])]),
            Builder\Block::make('studio_demo')->label($label('studio_demo'))->schema([$copy(['eyebrow' => 'text', 'title' => 'text', 'body' => 'textarea'])]),
            Builder\Block::make('features')->label($label('features'))->schema([$copy(['title' => 'text', 'subtitle' => 'textarea'], ['items' => ['title' => 'text', 'body' => 'textarea']])]),
            Builder\Block::make('pricing')->label($label('pricing'))->schema([$copy(['title' => 'text', 'subtitle' => 'textarea'])]),
            Builder\Block::make('testimonials')->label($label('testimonials'))->schema([$copy(['title' => 'text'], ['items' => ['quote' => 'textarea', 'name' => 'text', 'role' => 'text']])]),
            Builder\Block::make('faq')->label($label('faq'))->schema([$copy(['title' => 'text'], ['items' => ['q' => 'text', 'a' => 'textarea']])]),
            Builder\Block::make('cta')->label($label('cta'))->schema([$copy(['title' => 'text', 'body' => 'textarea', 'cta_label' => 'text', 'cta_url' => 'text'])]),
            Builder\Block::make('video')->label($label('video'))->schema([$copy(['title' => 'text']), TextInput::make('video_url')->label('YouTube / mp4 URL')->url()->required()]),
            Builder\Block::make('text')->label($label('text'))->schema([$copy(['title' => 'text', 'body' => 'textarea'])]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->columns([
            TextColumn::make('title')->label(__('fields.title'))->weight('semibold')->description(fn ($record) => '/'.($record->slug === 'home' ? '' : $record->slug)),
            TextColumn::make('sections')->label(__('website.admin.blocks'))->state(fn ($record) => count($record->sections ?? [])),
            IconColumn::make('is_published')->label(__('website.dashboard.published'))->boolean(),
            TextColumn::make('updated_at')->label(__('salon.ui.created'))->since(),
        ])->recordActions([
            Action::make('view')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->label(__('salon.ui.view'))->url(fn ($record) => static::publicUrl($record), shouldOpenInNewTab: true),
            EditAction::make(),
            DeleteAction::make(),
        ])->emptyStateHeading(__('website.admin.pages'))->emptyStateDescription(__('website.dashboard.empty_pages_body'));
    }

    public static function publicUrl(PlatformPage $page): string
    {
        return url(match (true) {
            $page->slug === 'home' => '/',
            in_array($page->slug, ['pricing', 'help'], true) => '/'.$page->slug,
            str_starts_with($page->slug, 'legal-') => '/legal/'.substr($page->slug, 6),
            str_starts_with($page->slug, 'features-') => '/features/'.substr($page->slug, 9),
            default => '/p/'.$page->slug,
        });
    }

    /** Defaults to pre-fill a new page with the designed built-in content. */
    public static function defaultSections(string $slug): array
    {
        return PlatformContent::defaults()[$slug]['sections'] ?? [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformPages::route('/'),
            'create' => CreatePlatformPage::route('/create'),
            'edit' => EditPlatformPage::route('/{record}/edit'),
        ];
    }
}
