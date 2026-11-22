<?php

namespace App\Filament\Dashboard\Resources\PosShifts;

use App\Domain\POS\Models\Shift;
use App\Filament\Dashboard\Resources\PosShifts\Pages\ListShifts;
use App\Filament\Dashboard\Resources\PosShifts\Pages\ViewShift;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $slug = 'pos-shifts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) tenant()?->hasModule('pos') && ($user?->can('pos.close_shift') || $user?->can('reports.view'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.nav.shifts');
    }

    public static function getModelLabel(): string
    {
        return __('pos.shift.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.shift.plural');
    }

    public static function zReportAction(): Action
    {
        return Action::make('zReport')->label(__('pos.shift.z_report'))->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
            ->url(fn (Shift $record) => route('pos.shift.z-report', $record->id), shouldOpenInNewTab: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['register', 'branch', 'user', 'closer']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('branch.name')->label(__('pos.sales.branch')),
                TextColumn::make('register.name')->label(__('pos.register.label')),
                TextColumn::make('user.name')->label(__('pos.shift.opened_by')),
                TextColumn::make('opened_at')->label(__('pos.shift.opened_at'))->dateTime('d M · H:i')->sortable(),
                TextColumn::make('closed_at')->label(__('pos.shift.closed_at'))->dateTime('d M · H:i')->placeholder('—'),
                TextColumn::make('status')->badge()->label(__('pos.sales.status'))
                    ->formatStateUsing(fn ($state) => __('pos.shift.status_'.$state))->color(fn ($state) => $state === 'open' ? 'success' : 'gray'),
                Money::column('expected_cash')->label(__('pos.shift.expected'))->placeholder('—'),
                Money::column('counted_cash')->label(__('pos.shift.counted'))->placeholder('—'),
                Money::column('variance')->label(__('pos.shift.variance'))->placeholder('—')
                    ->color(fn ($state) => $state === null ? null : ((int) $state < 0 ? 'danger' : ((int) $state > 0 ? 'warning' : 'success'))),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('pos.sales.status'))->options(['open' => __('pos.shift.status_open'), 'closed' => __('pos.shift.status_closed')]),
                SelectFilter::make('pos_register_id')->label(__('pos.register.label'))->relationship('register', 'name'),
            ])
            ->recordActions([ViewAction::make(), static::zReportAction()->visible(fn (Shift $record) => $record->status === 'closed')])
            ->emptyStateHeading(__('pos.shift.no_open'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $field) => TextEntry::make($field)->formatStateUsing(fn ($state) => $state === null ? '—' : money((int) $state));

        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('register.name')->label(__('pos.register.label')),
                TextEntry::make('user.name')->label(__('pos.shift.opened_by')),
                TextEntry::make('opened_at')->label(__('pos.shift.opened_at'))->dateTime(),
                TextEntry::make('closed_at')->label(__('pos.shift.closed_at'))->dateTime()->placeholder('—'),
                $money('opening_float')->label(__('pos.shift.opening_float')),
                $money('expected_cash')->label(__('pos.shift.expected')),
                $money('counted_cash')->label(__('pos.shift.counted')),
                $money('variance')->label(__('pos.shift.variance'))->weight('semibold'),
                TextEntry::make('notes')->label(__('pos.shift.notes'))->columnSpanFull()->placeholder('—'),
            ]),
            Section::make(__('pos.shift.by_method'))->columnSpanFull()->visible(fn (Shift $record) => ! empty($record->summary['by_method']))->schema([
                KeyValueEntry::make('summary.by_method')->hiddenLabel()
                    ->state(fn (Shift $record) => collect($record->summary['by_method'] ?? [])->mapWithKeys(fn ($row, $method) => [
                        ($method === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$method)) => money((int) $row['total']).' ('.$row['count'].')',
                    ])->all()),
            ]),
            Section::make(__('pos.shift.movements'))->columnSpanFull()->schema([
                RepeatableEntry::make('cashMovements')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->hiddenLabel()->dateTime('H:i'),
                    TextEntry::make('type')->hiddenLabel()->badge()->formatStateUsing(fn ($state) => __('pos.shift.movement_'.$state)),
                    TextEntry::make('reason')->hiddenLabel(),
                    TextEntry::make('amount')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($state) => money((int) $state)),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'view' => ViewShift::route('/{record}'),
        ];
    }
}
