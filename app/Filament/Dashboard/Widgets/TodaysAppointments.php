<?php

namespace App\Filament\Dashboard\Widgets;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingAccess;
use App\Filament\Dashboard\Support\BookingActions;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

/** "Today's appointments" on the owner command center, with one-tap check-in. */
class TodaysAppointments extends TableWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '20s';

    public static function canView(): bool
    {
        return BookingAccess::canView();
    }

    public function table(Table $table): Table
    {
        $tz = tenant()?->timezone ?? 'UTC';
        $start = CarbonImmutable::now($tz)->startOfDay()->utc();
        $own = BookingAccess::ownStaffId();

        return $table
            ->heading(__('booking.widget.title'))
            ->query(fn () => Booking::query()->with(['client', 'branch', 'items.service', 'items.staff'])
                ->whereBetween('starts_at', [$start, $start->addDay()])
                ->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value])
                ->when($own !== null, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('staff_id', $own)))
                ->orderBy('starts_at'))
            ->paginated([10, 25])
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('starts_at')->label(__('booking.widget.time'))
                    ->formatStateUsing(fn (Booking $record) => $record->starts_at->copy()->setTimezone($record->branch?->timezone ?? 'UTC')->format('H:i'))->weight('semibold'),
                TextColumn::make('client.name')->label(__('booking.admin.client'))->placeholder(__('booking.admin.walk_in'))->description(fn (Booking $record) => $record->client?->phone ? new HtmlString('<span dir="ltr">'.e($record->client->phone).'</span>') : null),
                TextColumn::make('services')->label(__('booking.admin.services'))->state(fn (Booking $record) => $record->items->map(fn ($i) => $i->service?->name)->filter()->implode(' + '))->wrap(),
                TextColumn::make('staff')->label(__('booking.admin.staff'))->state(fn (Booking $record) => $record->items->map(fn ($i) => $i->staff?->name)->filter()->unique()->implode(', ')),
                TextColumn::make('status')->label(__('salon.ui.status'))->badge(),
            ])
            ->recordActions([BookingActions::checkIn(), BookingActions::start(), BookingActions::complete()])
            ->emptyStateHeading(__('booking.widget.empty'))
            ->emptyStateDescription(__('booking.widget.empty_body'));
    }
}
