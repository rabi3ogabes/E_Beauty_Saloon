<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Booking\Actions\CheckInBooking;
use App\Domain\Booking\Actions\RescheduleBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingAccess;
use App\Filament\Dashboard\Support\BookingActions;
use App\Filament\Dashboard\Support\BookingForm;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Staff calendar: day view with one column per staff member (resource view), week view, colour by staff or
 * status, drag & drop move / resize (re-validated by the engine), quick-create on an empty slot, block time,
 * walk-ins and check-in. Built as a CSS-grid Livewire + Alpine component (FullCalendar's resource views are a
 * premium plugin). Realtime: listens to the private "tenant.{id}.bookings" channel when Echo is present and
 * polls every 20 seconds otherwise.
 */
class StaffCalendar extends Page
{
    protected string $view = 'filament.dashboard.booking.staff-calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'calendar';

    public const PX_PER_MINUTE = 1.6;

    #[Url]
    public string $date = '';

    #[Url]
    public string $mode = 'day';

    #[Url]
    public string $colorBy = 'staff';

    #[Url]
    public ?int $branchId = null;

    public static function canAccess(): bool
    {
        return BookingAccess::canView();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.bookings');
    }

    public static function getNavigationLabel(): string
    {
        return __('booking.calendar_page.title');
    }

    public function getTitle(): string
    {
        return __('booking.calendar_page.title');
    }

    public function mount(): void
    {
        $this->branchId ??= app(TenantContext::class)->branchId() ?? Branch::query()->active()->orderBy('sort')->value('id');
        $this->date = $this->date ?: CarbonImmutable::now($this->branch()?->timezone ?? 'UTC')->toDateString();
        $this->mode = in_array($this->mode, ['day', 'week'], true) ? $this->mode : 'day';
    }

    public function branch(): ?Branch
    {
        return $this->branchId ? Branch::find($this->branchId) : null;
    }

    // --- navigation ----------------------------------------------------------------------------

    public function shift(int $direction): void
    {
        $this->date = CarbonImmutable::parse($this->date)->addDays($direction * ($this->mode === 'week' ? 7 : 1))->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->branch()?->timezone ?? 'UTC')->toDateString();
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'week' ? 'week' : 'day';
    }

    // --- interactions (called from Alpine) -----------------------------------------------------

    /** Drag & drop: move a service to another staff column and/or time. $minutes = minutes after local midnight. */
    public function moveItem(int $itemId, ?int $staffId, string $date, int $minutes): void
    {
        if (! BookingAccess::canManage()) {
            return;
        }
        $item = BookingItem::query()->whereHas('booking')->findOrFail($itemId);
        $booking = Booking::with(['items', 'branch'])->findOrFail($item->booking_id);
        $this->guardOwn($booking);
        $index = $booking->items->values()->search(fn ($i) => $i->id === $item->id);
        $tz = $booking->branch->timezone;
        $newItemStart = CarbonImmutable::parse($date, $tz)->startOfDay()->addMinutes($minutes);
        $delta = $newItemStart->getTimestamp() - $item->starts_at->getTimestamp();
        $newStart = CarbonImmutable::instance($booking->starts_at)->addSeconds($delta);

        $staff = $staffId && $staffId !== $item->staff_id ? [$index => $staffId] : [];
        BookingActions::run(fn () => app(RescheduleBooking::class)->handle($booking, $newStart, ['by' => 'salon', 'staff' => $staff]), __('booking.admin.rescheduled'));
    }

    /** Resize a service block to a new duration (minutes). */
    public function resizeItem(int $itemId, int $duration): void
    {
        if (! BookingAccess::canManage()) {
            return;
        }
        $item = BookingItem::query()->whereHas('booking')->findOrFail($itemId);
        $booking = Booking::with(['items', 'branch'])->findOrFail($item->booking_id);
        $this->guardOwn($booking);
        $index = $booking->items->values()->search(fn ($i) => $i->id === $item->id);
        $duration = max(5, min(600, (int) (round($duration / 5) * 5)));

        BookingActions::run(fn () => app(RescheduleBooking::class)->handle($booking, CarbonImmutable::instance($booking->starts_at), ['by' => 'salon', 'durations' => [$index => $duration], 'notify' => false]),
            __('booking.admin.rescheduled'));
    }

    public function checkIn(int $bookingId): void
    {
        if (! BookingAccess::canManage()) {
            return;
        }
        $booking = Booking::findOrFail($bookingId);
        $this->guardOwn($booking);
        BookingActions::run(fn () => app(CheckInBooking::class)->handle($booking), __('booking.admin.updated', ['status' => BookingStatus::CheckedIn->getLabel()]));
    }

    protected function guardOwn(Booking $booking): void
    {
        if (($own = BookingAccess::ownStaffId()) !== null) {
            abort_unless($booking->items->contains('staff_id', $own), 403);
        }
    }

    // --- actions -------------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->walkInAction(),
            $this->blockTimeAction(),
            Action::make('newBooking')->label(__('booking.admin.new'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => BookingAccess::canManage())
                ->fillForm(fn () => ['branch_id' => $this->branchId, 'starts_at' => $this->date.' '.now($this->branch()?->timezone ?? 'UTC')->addHour()->startOfHour()->format('H:i')])
                ->schema(BookingForm::schema())
                ->action(fn (array $data) => BookingActions::run(fn () => BookingForm::create($data), __('booking.admin.created'))),
        ];
    }

    /** Quick-create on an empty slot: mounted from the grid with ['staff' => id, 'date' => Y-m-d, 'time' => H:i]. */
    public function quickCreateAction(): Action
    {
        return Action::make('quickCreate')->label(__('booking.admin.new'))
            ->visible(fn () => BookingAccess::canManage())
            ->fillForm(fn (array $arguments) => [
                'branch_id' => $this->branchId,
                'starts_at' => ($arguments['date'] ?? $this->date).' '.($arguments['time'] ?? '10:00'),
                'items' => [['service_id' => null, 'staff_id' => $arguments['staff'] ?? null]],
            ])
            ->schema(BookingForm::schema())
            ->action(fn (array $data) => BookingActions::run(fn () => BookingForm::create($data), __('booking.admin.created')));
    }

    public function walkInAction(): Action
    {
        return Action::make('walkIn')->label(__('booking.calendar_page.walk_in'))->icon(Heroicon::OutlinedBolt)->color('gray')
            ->visible(fn () => BookingAccess::canManage())
            ->fillForm(fn () => ['branch_id' => $this->branchId])
            ->schema(BookingForm::schema(withTime: false))
            ->action(function (array $data) {
                $branch = Branch::findOrFail($data['branch_id']);
                $now = CarbonImmutable::now($branch->timezone);
                $start = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 5) * 5);
                BookingActions::run(fn () => BookingForm::create($data, 'walk_in', BookingStatus::CheckedIn->value, $start), __('booking.calendar_page.walk_in_added'));
            });
    }

    public function blockTimeAction(): Action
    {
        return Action::make('blockTime')->label(__('booking.calendar_page.block_time'))->icon(Heroicon::OutlinedNoSymbol)->color('gray')
            ->visible(fn () => BookingAccess::canManage())
            ->fillForm(fn () => ['date' => $this->date, 'from' => '13:00', 'to' => '14:00'])
            ->schema([
                Select::make('staff_id')->label(__('booking.admin.staff'))->required()
                    ->options(fn () => $this->staffQuery()->get()->pluck('name', 'id')),
                Grid::make(3)->schema([
                    DatePicker::make('date')->label(__('booking.calendar_page.date'))->required()->native(false),
                    TimePicker::make('from')->label(__('booking.admin.from'))->seconds(false)->required(),
                    TimePicker::make('to')->label(__('booking.admin.until'))->seconds(false)->required()->after('from'),
                ]),
                TextInput::make('reason')->label(__('booking.admin.reason'))->maxLength(190),
            ])
            ->action(function (array $data) {
                $tz = $this->branch()?->timezone ?? 'UTC';
                TimeOff::create([
                    'staff_id' => $data['staff_id'], 'type' => 'block', 'status' => 'approved', 'approved_by' => auth()->id(), 'reason' => $data['reason'] ?? null,
                    'starts_at' => CarbonImmutable::parse($data['date'].' '.$data['from'], $tz)->utc(),
                    'ends_at' => CarbonImmutable::parse($data['date'].' '.$data['to'], $tz)->utc(),
                ]);
                Notification::make()->success()->title(__('booking.calendar_page.blocked'))->send();
            });
    }

    // --- data ----------------------------------------------------------------------------------

    protected function staffQuery()
    {
        $query = Staff::query()->withoutGlobalScope('branch_visibility')->where('is_active', true)
            ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $this->branchId))
            ->orderBy('sort')->orderBy('id');
        if (($own = BookingAccess::ownStaffId()) !== null) {
            $query->whereKey($own);
        }

        return $query;
    }

    protected function getViewData(): array
    {
        $branch = $this->branch();
        if (! $branch) {
            return ['branch' => null, 'branches' => collect()];
        }
        $tz = $branch->timezone;
        $day = CarbonImmutable::parse($this->date, $tz)->startOfDay();
        $days = $this->mode === 'week' ? collect(range(0, 6))->map(fn ($i) => $day->startOfWeek(CarbonImmutable::SUNDAY)->addDays($i)) : collect([$day]);
        $from = $days->first();
        $to = $days->last()->endOfDay();

        // Visible hours: union of opening hours of the visible days (fallback 09–21), padded to full hours.
        $open = 24 * 60;
        $close = 0;
        foreach ($days as $d) {
            if ($h = $branch->hoursFor($d)) {
                $open = min($open, $this->minutes($h['open']));
                $c = $this->minutes($h['close']);
                $close = max($close, $c <= $this->minutes($h['open']) ? 24 * 60 : $c);
            }
        }
        if ($open >= $close) {
            [$open, $close] = [9 * 60, 21 * 60];
        }
        $open = intdiv($open, 60) * 60;
        $close = (int) ceil($close / 60) * 60;

        $staff = $this->staffQuery()->get();
        $staffIds = $staff->pluck('id')->all();

        $items = BookingItem::query()
            ->whereHas('booking', fn ($q) => $q->where('branch_id', $branch->id)->whereNotIn('status', [BookingStatus::CancelledByClient->value, BookingStatus::CancelledBySalon->value]))
            ->when(BookingAccess::ownStaffId() !== null, fn ($q) => $q->whereIn('staff_id', $staffIds ?: [0]))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->with(['booking.client', 'service', 'staff'])->orderBy('starts_at')->get();

        $timeOff = TimeOff::query()->approved()->whereIn('staff_id', $staffIds ?: [0])
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get();

        $statusColors = [
            'pending_payment' => '#C48A3A', 'confirmed' => '#6B7FA3', 'checked_in' => '#C8A96A', 'in_service' => '#A88B4F',
            'completed' => '#4F7A5A', 'no_show' => '#A4473F',
        ];

        $event = function (BookingItem $i) use ($tz, $open, $statusColors) {
            $local = $i->starts_at->copy()->setTimezone($tz);
            $startMin = (int) $local->format('G') * 60 + (int) $local->format('i');

            return [
                'id' => $i->id, 'booking_id' => $i->booking_id, 'staff_id' => $i->staff_id, 'date' => $local->toDateString(),
                'reference' => $i->booking->reference, 'status' => $i->booking->status->value, 'status_label' => $i->booking->status->getLabel(),
                'client' => $i->booking->client?->name ?? __('booking.admin.walk_in'), 'phone' => $i->booking->client?->phone,
                'service' => $i->service?->name.($i->guest_name ? ' · '.$i->guest_name : ''), 'staff' => $i->staff?->name,
                'time' => $local->format('H:i').'–'.$i->ends_at->copy()->setTimezone($tz)->format('H:i'),
                'start' => $startMin, 'duration' => (int) $i->duration_minutes,
                'top' => round(($startMin - $open) * self::PX_PER_MINUTE), 'height' => max(22, round($i->duration_minutes * self::PX_PER_MINUTE) - 2),
                'color' => $this->colorBy === 'status' ? ($statusColors[$i->booking->status->value] ?? '#7A736A') : ($i->staff?->color ?? '#C8A96A'),
                'can_check_in' => $i->booking->status === BookingStatus::Confirmed,
                'url' => BookingResource::getUrl('view', ['record' => $i->booking_id]),
            ];
        };

        $events = $items->map($event)->values();
        if ($this->mode === 'week') {
            // Lanes for overlapping events inside each day column.
            $events = $events->groupBy('date')->flatMap(function ($dayEvents) {
                $lanes = [];
                $out = [];
                foreach ($dayEvents->sortBy('start') as $e) {
                    $lane = 0;
                    while (isset($lanes[$lane]) && $lanes[$lane] > $e['start']) {
                        $lane++;
                    }
                    $lanes[$lane] = $e['start'] + $e['duration'];
                    $out[] = $e + ['lane' => $lane];
                }
                $count = max(1, count($lanes));

                return array_map(fn ($e) => $e + ['lanes' => $count], $out);
            })->values();
        }

        $blocks = $timeOff->map(function ($off) use ($tz, $open, $close) {
            $s = $off->starts_at->copy()->setTimezone($tz);
            $e = $off->ends_at->copy()->setTimezone($tz);
            $startMin = max($open, (int) $s->format('G') * 60 + (int) $s->format('i'));
            $endMin = $e->toDateString() > $s->toDateString() ? $close : min($close, (int) $e->format('G') * 60 + (int) $e->format('i'));

            return ['staff_id' => $off->staff_id, 'date' => $s->toDateString(), 'type' => __('booking.calendar_page.time_off_types.'.$off->type), 'reason' => $off->reason,
                'top' => round(($startMin - $open) * self::PX_PER_MINUTE), 'height' => max(10, round(($endMin - $startMin) * self::PX_PER_MINUTE))];
        })->values();

        return [
            'branch' => $branch,
            'branches' => Branch::query()->active()->orderBy('sort')->get(),
            'tz' => $tz,
            'days' => $days,
            'staff' => $staff,
            'events' => $events,
            'blocks' => $blocks,
            'open' => $open,
            'close' => $close,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'nowMinute' => CarbonImmutable::now($tz)->toDateString() === $this->date || $this->mode === 'week'
                ? (int) CarbonImmutable::now($tz)->format('G') * 60 + (int) CarbonImmutable::now($tz)->format('i') : null,
            'todayDate' => CarbonImmutable::now($tz)->toDateString(),
            'canManage' => BookingAccess::canManage(),
            'statusColors' => $statusColors,
            'channel' => 'tenant.'.tenant()->id.'.bookings',
        ];
    }

    protected function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)));

        return $h * 60 + $m;
    }
}
