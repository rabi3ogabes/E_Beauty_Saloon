<?php

namespace App\Filament\Dashboard\Resources\Staff;

use App\Domain\Catalog\Models\Service;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Staff\Pages\CreateStaff;
use App\Filament\Dashboard\Resources\Staff\Pages\EditStaff;
use App\Filament\Dashboard\Resources\Staff\Pages\ListStaff;
use App\Filament\Dashboard\Resources\Staff\RelationManagers\CommissionRulesRelationManager;
use App\Filament\Dashboard\Resources\Staff\RelationManagers\SchedulesRelationManager;
use App\Filament\Dashboard\Resources\Staff\RelationManagers\TimeOffRelationManager;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Filament\Support\Uploads;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Staff::class;

    protected static string $viewPermission = 'staff.view';

    protected static string $managePermission = 'staff.manage';

    protected static string $navGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'staff';

    public static function getModelLabel(): string
    {
        return __('workspace.staff.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.staff.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.staff.profile'))->description(__('workspace.staff.profile_help'))->columns(2)->schema([
                    Translatable::make('name', __('fields.name')),
                    Translatable::make('title', __('fields.title'), required: false),
                    Translatable::make('bio', __('fields.bio'), required: false, textarea: true),
                    TextInput::make('phone')->label(__('fields.phone'))->tel()->maxLength(20),
                    TextInput::make('email')->label(__('fields.email'))->email(),
                    Select::make('gender')->label(__('workspace.common.gender'))->options(['female' => __('workspace.genders.female'), 'male' => __('workspace.genders.male')])->native(false),
                ]),
                Section::make(__('workspace.staff.skills'))->description(__('workspace.staff.skills_help'))->schema([
                    Repeater::make('skills')->hiddenLabel()->columns(3)->defaultItems(0)->reorderable(false)
                        ->addActionLabel(__('workspace.staff.add_skill'))
                        ->itemLabel(fn (array $state) => Service::find($state['service_id'] ?? 0)?->name)
                        ->collapsible()
                        ->schema([
                            Select::make('service_id')->label(__('workspace.services.label'))->required()->searchable()->distinct()
                                ->options(fn () => Service::query()->orderBy('sort')->get()->pluck('name', 'id'))->native(false),
                            Money::input('price')->label(__('workspace.staff.own_price'))->placeholder(__('workspace.common.inherit')),
                            TextInput::make('duration_minutes')->label(__('workspace.staff.own_duration'))->numeric()->minValue(5)->suffix(__('salon.ui.minutes'))->placeholder(__('workspace.common.inherit')),
                        ]),
                ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.staff.appearance'))->schema([
                    FileUpload::make('photo_path')->label(__('workspace.staff.photo'))->image()->acceptedFileTypes(Uploads::IMAGE_TYPES)->avatar()->imageEditor()->disk('public')->directory('staff')->maxSize(4096),
                    ColorPicker::make('color')->label(__('workspace.staff.color'))->helperText(__('workspace.staff.color_help'))->default('#C8A96A')->required(),
                ]),
                Section::make(__('workspace.staff.availability'))->schema([
                    CheckboxList::make('branches')->label(__('fields.branches'))->relationship('branches', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->bulkToggleable(),
                    Toggle::make('is_bookable')->label(__('workspace.staff.bookable'))->helperText(__('workspace.staff.bookable_help'))->default(true),
                    Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branches', 'user']))
            ->defaultSort('sort')
            ->reorderable('sort', fn () => static::canManage())
            ->columns([
                ImageColumn::make('photo_path')->label('')->disk('public')->circular()->defaultImageUrl(fn (Staff $r) => 'https://ui-avatars.com/api/?background=E9D6CF&color=1C1A17&name='.urlencode((string) $r->name))->width(40),
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable(query: fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
                    ->description(fn (Staff $r) => $r->title),
                ColorColumn::make('color')->label(__('workspace.staff.color')),
                TextColumn::make('branches.name')->label(__('fields.branches'))->badge()->color('gray'),
                TextColumn::make('services_count')->counts('services')->label(__('workspace.staff.skills'))->alignCenter(),
                TextColumn::make('rating')->label(__('workspace.staff.rating'))->formatStateUsing(fn ($state, Staff $r) => $r->ratings_count ? number_format((float) $state, 1).' ★' : '—')->alignCenter(),
                IconColumn::make('user_id')->label(__('workspace.staff.has_login'))->boolean(fn ($state) => (bool) $state)->alignCenter()->toggleable(),
                IconColumn::make('is_bookable')->label(__('workspace.staff.bookable'))->boolean(),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('branch')->label(__('salon.ui.branch'))->relationship('branches', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                TernaryFilter::make('is_bookable')->label(__('workspace.staff.bookable')),
                TernaryFilter::make('is_active')->label(__('fields.is_active')),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading(__('workspace.staff.empty'))
            ->emptyStateDescription(__('workspace.staff.empty_body'));
    }

    public static function getRelations(): array
    {
        return [SchedulesRelationManager::class, TimeOffRelationManager::class, CommissionRulesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }

    public static function skillRows(Staff $staff): array
    {
        return $staff->services()->get()->map(fn ($s) => [
            'service_id' => $s->id, 'price' => $s->pivot->price, 'duration_minutes' => $s->pivot->duration_minutes,
        ])->all();
    }
}
