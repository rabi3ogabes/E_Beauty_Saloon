<?php

namespace App\Filament\Dashboard\Resources\Branches;

use App\Domain\Branches\Models\Branch;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Branches\Pages\CreateBranch;
use App\Filament\Dashboard\Resources\Branches\Pages\EditBranch;
use App\Filament\Dashboard\Resources\Branches\Pages\ListBranches;
use App\Filament\Dashboard\Resources\Branches\RelationManagers\HolidaysRelationManager;
use App\Filament\Dashboard\Resources\Branches\RelationManagers\ResourcesRelationManager;
use App\Filament\Support\Translatable;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class BranchResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Branch::class;

    protected static string $viewPermission = 'branches.view';

    protected static string $managePermission = 'branches.manage';

    protected static string $navGroup = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('workspace.branches.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.branches.plural');
    }

    public const TIMEZONES = ['Asia/Qatar', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kuwait', 'Asia/Bahrain', 'Asia/Muscat', 'Europe/London', 'Europe/Paris'];

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.branches.identity'))->description(__('workspace.branches.identity_help'))->columns(2)->schema([
                    Translatable::make('name', __('fields.name')),
                    Select::make('status')->label(__('fields.status'))->options(['active' => __('workspace.common.active'), 'inactive' => __('workspace.common.inactive')])->default('active')->required()->native(false),
                    TextInput::make('slug')->label(__('fields.slug'))->alphaDash()->maxLength(60)->helperText(__('workspace.branches.slug_help'))
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', tenant()?->id)),
                    TextInput::make('phone')->label(__('fields.phone'))->tel()->maxLength(20)->placeholder('+974 4444 0000'),
                    TextInput::make('whatsapp')->label(__('workspace.branches.whatsapp'))->tel()->maxLength(20)->placeholder('+974 5555 0000'),
                    TextInput::make('email')->label(__('fields.email'))->email()->columnSpanFull(),
                ]),
                Section::make(__('workspace.branches.location'))->description(__('workspace.branches.location_help'))->columns(2)->schema([
                    Translatable::make('address', __('fields.address'), required: false, textarea: true, rows: 2),
                    TextInput::make('city')->label(__('workspace.branches.city'))->default('Doha'),
                    TextInput::make('area')->label(__('workspace.branches.area'))->placeholder('West Bay, The Pearl, Lusail…'),
                    TextInput::make('lat')->label(__('workspace.branches.lat'))->numeric()->minValue(-90)->maxValue(90)->live(onBlur: true)->placeholder('25.2854'),
                    TextInput::make('lng')->label(__('workspace.branches.lng'))->numeric()->minValue(-180)->maxValue(180)->live(onBlur: true)->placeholder('51.5310'),
                    Text::make(fn (Get $get) => static::mapPreview($get('lat'), $get('lng')))->columnSpanFull(),
                    Select::make('timezone')->label(__('fields.timezone'))->options(array_combine(self::TIMEZONES, self::TIMEZONES))->default('Asia/Qatar')->required()->native(false),
                ]),
                Section::make(__('workspace.branches.hours'))->description(__('workspace.branches.hours_help'))->schema([
                    static::hoursRepeater(),
                ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.branches.channels'))->schema([
                    Toggle::make('booking_enabled')->label(__('workspace.branches.booking_enabled'))->helperText(__('workspace.branches.booking_enabled_help'))->default(true),
                    Toggle::make('shop_pickup_enabled')->label(__('workspace.branches.pickup_enabled'))->helperText(__('workspace.branches.pickup_enabled_help'))->default(true),
                ]),
                Section::make(__('workspace.branches.tax'))->description(__('workspace.branches.tax_help'))->schema([
                    TextInput::make('tax_percent')->label(__('workspace.branches.tax_percent'))->numeric()->minValue(0)->maxValue(100)->step('0.01')->suffix('%')->default(0),
                    Toggle::make('tax_inclusive')->label(__('workspace.branches.tax_inclusive'))->default(true),
                ]),
                Section::make(__('workspace.branches.media'))->schema([
                    FileUpload::make('cover_path')->label(__('workspace.branches.cover'))->image()->disk('public')->directory('branches/covers')->imageEditor()->maxSize(4096),
                    FileUpload::make('gallery')->label(__('workspace.branches.gallery'))->image()->multiple()->reorderable()->maxFiles(12)->disk('public')->directory('branches/gallery')->maxSize(4096),
                ]),
            ]),
        ]);
    }

    public static function hoursRepeater(string $name = 'hours'): Repeater
    {
        return Repeater::make($name)->hiddenLabel()
            ->addable(false)->deletable(false)->reorderable(false)->collapsible()
            ->itemLabel(fn (array $state) => __('workspace.weekdays.'.($state['day'] ?? 'sun')).(($state['closed'] ?? false) ? ' · '.__('workspace.branches.closed') : ' · '.substr((string) ($state['open'] ?? ''), 0, 5).'–'.substr((string) ($state['close'] ?? ''), 0, 5)))
            ->columns(['default' => 2, 'md' => 4])
            ->schema([
                TextInput::make('day')->hidden()->dehydrated(),
                Toggle::make('closed')->label(__('workspace.branches.closed'))->inline(false)->live(),
                TimePicker::make('open')->label(__('workspace.branches.opens'))->seconds(false)->required(fn (Get $get) => ! $get('closed'))->hidden(fn (Get $get) => (bool) $get('closed')),
                TimePicker::make('close')->label(__('workspace.branches.closes'))->seconds(false)->required(fn (Get $get) => ! $get('closed'))->hidden(fn (Get $get) => (bool) $get('closed'))->after('open'),
                Repeater::make('breaks')->label(__('workspace.branches.breaks'))->columnSpanFull()->columns(2)->defaultItems(0)->reorderable(false)
                    ->addActionLabel(__('workspace.branches.add_break'))->hidden(fn (Get $get) => (bool) $get('closed'))
                    ->schema([
                        TimePicker::make('from')->label(__('workspace.common.from'))->seconds(false)->required(),
                        TimePicker::make('to')->label(__('workspace.common.to'))->seconds(false)->required()->after('from'),
                    ]),
            ]);
    }

    public static function mapPreview(mixed $lat, mixed $lng): HtmlString
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('workspace.branches.map_hint')).'</p>');
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        $bbox = implode(',', [$lng - 0.01, $lat - 0.006, $lng + 0.01, $lat + 0.006]);
        $src = 'https://www.openstreetmap.org/export/embed.html?bbox='.urlencode($bbox).'&layer=mapnik&marker='.$lat.','.$lng;

        return new HtmlString('<div class="overflow-hidden rounded-2xl border border-gray-200 dark:border-white/10"><iframe title="map" class="h-56 w-full" loading="lazy" src="'.e($src).'"></iframe></div>'
            .'<a class="mt-2 inline-block text-xs text-primary-600 underline" target="_blank" rel="noopener" href="https://www.google.com/maps?q='.$lat.','.$lng.'">'.e(__('workspace.branches.open_in_maps')).'</a>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort', fn () => static::canManage())
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()
                    ->description(fn (Branch $r) => collect([$r->area, $r->city])->filter()->join(', ') ?: null),
                TextColumn::make('phone')->label(__('fields.phone'))->placeholder('—')->toggleable(),
                TextColumn::make('status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('workspace.common.'.$state))->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('open_now')->label(__('workspace.branches.today'))->state(fn (Branch $r) => $r->isOpenNow() ? __('workspace.branches.open_now') : __('workspace.branches.closed_now'))
                    ->badge()->color(fn (Branch $r) => $r->isOpenNow() ? 'success' : 'gray'),
                IconColumn::make('booking_enabled')->label(__('workspace.branches.booking'))->boolean(),
                IconColumn::make('shop_pickup_enabled')->label(__('workspace.branches.pickup'))->boolean()->toggleable(),
                TextColumn::make('resources_count')->counts('resources')->label(__('workspace.branches.resources'))->alignCenter()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('fields.status'))->options(['active' => __('workspace.common.active'), 'inactive' => __('workspace.common.inactive')]),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading(__('workspace.branches.empty'))
            ->emptyStateDescription(__('workspace.branches.empty_body'));
    }

    public static function getRelations(): array
    {
        return [HolidaysRelationManager::class, ResourcesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
