<?php

namespace App\Filament\Dashboard\Resources\Bookings;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Resources\Bookings\Pages\ListBookings;
use App\Filament\Dashboard\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Dashboard\Support\BookingAccess;
use App\Filament\Dashboard\Support\BookingActions;
use App\Filament\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.bookings');
    }

    public static function getModelLabel(): string
    {
        return __('booking.admin.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('booking.admin.plural');
    }

    public static function canViewAny(): bool
    {
        return BookingAccess::canView();
    }

    public static function canCreate(): bool
    {
        return false; // created through the CreateBooking action (header action / calendar)
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return BookingAccess::canView();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (($own = BookingAccess::ownStaffId()) !== null) {
            $query->whereHas('items', fn ($q) => $q->where('staff_id', $own));
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! BookingAccess::canView()) {
            return null;
        }
        $count = static::getEloquentQuery()->where('status', BookingStatus::PendingPayment->value)->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'branch', 'items.service', 'items.staff']))
            ->defaultSort('starts_at', 'desc')
            ->poll('20s')
            ->columns([
                TextColumn::make('starts_at')->label(__('booking.when'))->sortable()
                    ->formatStateUsing(fn (Booking $record) => $record->starts_at->copy()->setTimezone($record->branch?->timezone ?? 'UTC')->translatedFormat('D j M · H:i'))
                    ->description(fn (Booking $record) => $record->reference),
                TextColumn::make('client.name')->label(__('booking.admin.client'))->searchable(['name', 'phone'])->placeholder(__('booking.admin.walk_in'))
                    ->description(fn (Booking $record) => $record->client?->phone),
                TextColumn::make('services')->label(__('booking.admin.services'))
                    ->state(fn (Booking $record) => $record->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + '))->wrap(),
                TextColumn::make('staff')->label(__('booking.admin.staff'))
                    ->state(fn (Booking $record) => $record->items->map(fn ($i) => $i->staff?->name)->filter()->unique()->implode(', '))->toggleable(),
                TextColumn::make('branch.name')->label(__('salon.ui.branch'))->toggleable(),
                TextColumn::make('status')->label(__('salon.ui.status'))->badge()->sortable(),
                Money::column('total')->label(__('booking.flow.total'))->alignEnd(),
                Money::column('amount_paid')->label(__('booking.paid_online'))->alignEnd()->toggleable(),
                TextColumn::make('source')->label(__('booking.admin.source'))->formatStateUsing(fn ($state) => __('booking.sources.'.$state))->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference')->label(__('booking.reference'))->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('salon.ui.status'))->options(BookingStatus::class)->multiple(),
                SelectFilter::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => Branch::query()->get()->pluck('name', 'id')),
                SelectFilter::make('staff')->label(__('booking.admin.staff'))
                    ->options(fn () => Staff::query()->orderBy('sort')->get()->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('items', fn ($q) => $q->where('staff_id', $data['value'])) : $query),
                SelectFilter::make('source')->label(__('booking.admin.source'))->options(collect(__('booking.sources'))->all()),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label(__('booking.admin.from')),
                        DatePicker::make('until')->label(__('booking.admin.until')),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', CarbonImmutable::parse($d, tenant()?->timezone ?? 'UTC')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', CarbonImmutable::parse($d, tenant()?->timezone ?? 'UTC')->endOfDay()->utc()))),
            ])
            ->recordActions([
                BookingActions::checkIn(),
                ViewAction::make(),
                ActionGroup::make([BookingActions::confirm(), BookingActions::start(), BookingActions::complete(), BookingActions::reschedule(), BookingActions::noShow(), BookingActions::cancel()]),
            ])
            ->emptyStateHeading(__('booking.admin.empty'))
            ->emptyStateDescription(__('booking.admin.empty_body'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = fn (Booking $record) => $record->branch?->timezone ?? 'UTC';

        return $schema->columns(3)->components([
            Section::make(__('booking.admin.details'))->columnSpan(2)->columns(2)->schema([
                TextEntry::make('reference')->label(__('booking.reference'))->copyable()->weight('semibold')->size('lg'),
                TextEntry::make('status')->label(__('salon.ui.status'))->badge(),
                TextEntry::make('starts_at')->label(__('booking.when'))
                    ->formatStateUsing(fn (Booking $record) => $record->starts_at->copy()->setTimezone($tz($record))->translatedFormat('l j F Y · H:i').' – '.$record->ends_at->copy()->setTimezone($tz($record))->format('H:i')),
                TextEntry::make('branch.name')->label(__('salon.ui.branch')),
                TextEntry::make('client.name')->label(__('booking.admin.client'))->placeholder(__('booking.admin.walk_in'))->helperText(fn (Booking $record) => $record->client?->phone),
                TextEntry::make('source')->label(__('booking.admin.source'))->formatStateUsing(fn ($state) => __('booking.sources.'.$state)),
                TextEntry::make('client_notes')->label(__('booking.flow.notes'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('internal_notes')->label(__('booking.admin.internal_notes'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('look_reference')->label(__('booking.admin.look'))->placeholder('—'),
                TextEntry::make('utm_source')->label(__('booking.admin.attribution'))->placeholder('—')
                    ->formatStateUsing(fn (Booking $record) => trim(($record->utm_source ?? '').($record->campaign_id ? ' · #'.$record->campaign_id : ''))),
            ]),
            Section::make(__('booking.admin.money'))->columnSpan(1)->schema([
                TextEntry::make('total')->label(__('booking.flow.total'))->formatStateUsing(fn (Booking $record) => money($record->total, $record->currency))->weight('semibold'),
                TextEntry::make('deposit_required')->label(__('booking.admin.deposit'))->formatStateUsing(fn (Booking $record) => money($record->deposit_required, $record->currency)),
                TextEntry::make('amount_paid')->label(__('booking.paid_online'))->formatStateUsing(fn (Booking $record) => money($record->amount_paid, $record->currency))->color('success'),
                TextEntry::make('balance')->label(__('booking.balance_due'))->state(fn (Booking $record) => money($record->balanceDue(), $record->currency)),
                TextEntry::make('fee_charged')->label(__('booking.fee_charged'))->formatStateUsing(fn (Booking $record) => money($record->fee_charged, $record->currency)),
                TextEntry::make('payment_preference')->label(__('booking.admin.payment_preference'))->formatStateUsing(fn ($state) => __('booking.payment_preferences.'.$state)),
            ]),
            Section::make(__('booking.admin.services'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('service.name')->label(__('booking.admin.service'))->weight('semibold'),
                    TextEntry::make('staff.name')->label(__('booking.admin.staff'))->placeholder('—'),
                    TextEntry::make('starts_at')->label(__('booking.admin.starts_at'))
                        ->formatStateUsing(fn ($record) => $record->starts_at->copy()->setTimezone($record->booking?->branch?->timezone ?? 'UTC')->format('H:i').' · '.$record->duration_minutes.' '.__('salon.ui.minutes')),
                    TextEntry::make('resource.name')->label(__('booking.admin.resource'))->placeholder('—'),
                    TextEntry::make('price')->label(__('booking.admin.price'))->formatStateUsing(fn ($state) => money((int) $state)),
                ]),
            ]),
            Section::make(__('booking.admin.payments'))->columnSpan(2)->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->columns(4)->placeholder(__('booking.admin.no_payments'))->schema([
                    TextEntry::make('purpose')->label(__('booking.admin.purpose')),
                    TextEntry::make('amount')->label(__('booking.admin.amount'))->formatStateUsing(fn ($state, $record) => money((int) $state, $record->currency)),
                    TextEntry::make('status')->label(__('salon.ui.status'))->badge(),
                    TextEntry::make('created_at')->label(__('salon.ui.created'))->since(),
                ]),
            ]),
            Section::make(__('booking.admin.history'))->columnSpan(1)->schema([
                RepeatableEntry::make('history')->hiddenLabel()->schema([
                    TextEntry::make('to_status')->hiddenLabel()->badge()
                        ->helperText(fn ($record) => trim(($record->note ?? '').' · '.$record->created_at?->diffForHumans().($record->user ? ' · '.$record->user->name : ''), ' ·')),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }
}
