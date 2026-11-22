<?php

namespace App\Filament\Admin\Resources\BlogPosts;

use App\Domain\Websites\Actions\OptimizeImage;
use App\Domain\Websites\Models\BlogPost;
use App\Filament\Admin\Resources\BlogPosts\Pages\ManageBlogPosts;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BlogPostResource extends Resource
{
    protected static ?string $model = BlogPost::class;

    protected static ?string $slug = 'blog-posts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.website');
    }

    public static function getModelLabel(): string
    {
        return __('website.admin.post');
    }

    public static function getPluralModelLabel(): string
    {
        return __('website.admin.posts');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.cms.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->schema([
                Translatable::make('title', __('fields.title')),
                Translatable::make('excerpt', __('website.admin.excerpt'), required: false, textarea: true, rows: 2),
                Tabs::make()->tabs([
                    Tabs\Tab::make('English')->schema([
                        RichEditor::make('body.en')->label(__('website.admin.body'))
                            ->afterStateHydrated(fn ($component, $record) => $record && $component->state($record->getTranslation('body', 'en', false) ?: null)),
                    ]),
                    Tabs\Tab::make('العربية')->schema([
                        RichEditor::make('body.ar')->label(__('website.admin.body'))->extraInputAttributes(['dir' => 'rtl'])
                            ->afterStateHydrated(fn ($component, $record) => $record && $component->state($record->getTranslation('body', 'ar', false) ?: null)),
                    ]),
                ]),
            ]),
            Section::make()->columnSpan(1)->schema([
                TextInput::make('slug')->label(__('fields.slug'))->required()->alphaDash()->unique(ignoreRecord: true),
                DateTimePicker::make('published_at')->label(__('website.admin.published_at')),
                TextInput::make('author_name')->label(__('website.admin.author')),
                FileUpload::make('cover_path')->label(__('website.admin.cover'))->image()->disk('public')->directory('platform/blog')->maxSize(8192)
                    ->saveUploadedFileUsing(function ($file) {
                        $path = $file->store('platform/blog', 'public');

                        return app(OptimizeImage::class)->handle($path);
                    }),
                TagsInput::make('tags')->label(__('website.admin.tags')),
                Translatable::make('seo_title', __('website.dashboard.seo_title'), required: false),
                Translatable::make('seo_description', __('website.dashboard.seo_description'), required: false, textarea: true, rows: 2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('published_at', 'desc')->columns([
            ImageColumn::make('cover_path')->disk('public')->label('')->square(),
            TextColumn::make('title')->label(__('fields.title'))->weight('semibold')->searchable()->description(fn ($record) => '/blog/'.$record->slug),
            TextColumn::make('published_at')->label(__('website.admin.published_at'))->dateTime()->placeholder('—')->sortable(),
            TextColumn::make('author_name')->label(__('website.admin.author'))->placeholder('—'),
        ])->recordActions([
            Action::make('view')->label(__('salon.ui.view'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->url(fn ($record) => url('/blog/'.$record->slug), shouldOpenInNewTab: true),
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBlogPosts::route('/')];
    }
}
