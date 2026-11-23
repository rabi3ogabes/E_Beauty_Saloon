<?php

namespace App\Filament\Dashboard\Resources\PosRegisters;

use App\Domain\Branches\Models\Branch;
use App\Domain\POS\Models\Register;
use App\Filament\Dashboard\Resources\PosRegisters\Pages\ManageRegisters;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RegisterResource extends Resource
{
    protected static ?string $model = Register::class;

    protected static ?string $slug = 'pos-registers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        return (bool) tenant()?->hasModule('pos') && (auth()->user()?->can('pos.close_shift') ?? false);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.registers');
    }

    public static function getModelLabel(): string
    {
        return __('pos.register.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.register.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label(__('pos.sales.branch'))->required()
                ->options(fn () => Branch::query()->orderBy('sort')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name]))
                ->default(fn () => Branch::query()->value('id')),
            TextInput::make('name')->label(__('salon.ui.name'))->required()->maxLength(80),
            TextInput::make('code')->label(__('pos.register.code'))->maxLength(12),
            Money::input('default_float')->label(__('pos.register.default_float'))->default(0),
            Toggle::make('is_active')->label(__('fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'openShift.user']))
            ->columns([
                TextColumn::make('name')->label(__('salon.ui.name'))->weight('semibold')->searchable(),
                TextColumn::make('branch.name')->label(__('pos.sales.branch')),
                TextColumn::make('code')->label(__('pos.register.code'))->placeholder('—'),
                Money::column('default_float')->label(__('pos.register.default_float')),
                TextColumn::make('openShift.user.name')->label(__('pos.register.open_shift'))->placeholder('—')->badge()->color('success'),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])
            ->emptyStateHeading(__('pos.register.none_title'))
            ->emptyStateDescription(__('pos.register.none_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageRegisters::route('/')];
    }
}
