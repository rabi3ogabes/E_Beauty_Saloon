<?php

namespace App\Filament\Dashboard\Support;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CheckInBooking;
use App\Domain\Booking\Actions\CompleteBooking;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\MarkNoShow;
use App\Domain\Booking\Actions\RescheduleBooking;
use App\Domain\Booking\Actions\StartService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Status actions shared by the bookings table, the booking page and the calendar. */
class BookingActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::confirm(), self::checkIn(), self::start(), self::complete(), self::reschedule(), self::noShow(), self::cancel()];
    }

    public static function confirm(): Action
    {
        return self::transition('confirm', BookingStatus::Confirmed, Heroicon::OutlinedCheck, 'info', ConfirmBooking::class);
    }

    public static function checkIn(): Action
    {
        return self::transition('checkIn', BookingStatus::CheckedIn, Heroicon::OutlinedHandRaised, 'primary', CheckInBooking::class);
    }

    public static function start(): Action
    {
        return self::transition('startService', BookingStatus::InService, Heroicon::OutlinedSparkles, 'primary', StartService::class);
    }

    public static function complete(): Action
    {
        return self::transition('complete', BookingStatus::Completed, Heroicon::OutlinedCheckBadge, 'success', CompleteBooking::class);
    }

    public static function noShow(): Action
    {
        return self::transition('noShow', BookingStatus::NoShow, Heroicon::OutlinedUserMinus, 'danger', MarkNoShow::class)
            ->requiresConfirmation()
            ->modalDescription(fn (Booking $record) => $record->amount_paid > 0 ? __('booking.admin.no_show_fee_note', ['amount' => money($record->amount_paid, $record->currency)]) : null)
            ->visible(fn (Booking $record) => BookingAccess::canManage() && $record->status->canTransitionTo(BookingStatus::NoShow) && $record->starts_at->isPast());
    }

    public static function cancel(): Action
    {
        return Action::make('cancelBooking')
            ->label(__('booking.admin.actions.cancel'))->icon(Heroicon::OutlinedXMark)->color('danger')
            ->visible(fn (Booking $record) => BookingAccess::canManage() && $record->status->canTransitionTo(BookingStatus::CancelledBySalon))
            ->schema([
                TextInput::make('reason')->label(__('booking.admin.reason'))->maxLength(250),
                Toggle::make('client_requested')->label(__('booking.admin.client_requested'))->helperText(__('booking.admin.client_requested_help')),
            ])
            ->action(function (Booking $record, array $data) {
                self::run(fn () => app(CancelBooking::class)->handle($record, ($data['client_requested'] ?? false) ? 'client' : 'salon', $data['reason'] ?? null),
                    __('booking.admin.cancelled'));
            });
    }

    public static function reschedule(): Action
    {
        return Action::make('reschedule')
            ->label(__('booking.admin.actions.reschedule'))->icon(Heroicon::OutlinedCalendarDays)->color('gray')
            ->visible(fn (Booking $record) => BookingAccess::canManage() && in_array($record->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed, BookingStatus::CheckedIn], true))
            ->fillForm(fn (Booking $record) => ['starts_at' => $record->starts_at->copy()->setTimezone($record->branch->timezone)->format('Y-m-d H:i')])
            ->schema([
                DateTimePicker::make('starts_at')->label(__('booking.admin.new_time'))->seconds(false)->minutesStep(5)->native(false)->required(),
                Toggle::make('keep_staff')->label(__('booking.admin.keep_staff'))->default(true),
            ])
            ->action(function (Booking $record, array $data) {
                $start = CarbonImmutable::parse($data['starts_at'], $record->branch->timezone);
                self::run(fn () => app(RescheduleBooking::class)->handle($record, $start, ['by' => 'salon', 'keep_staff' => (bool) ($data['keep_staff'] ?? true)]), __('booking.admin.rescheduled'));
            });
    }

    protected static function transition(string $name, BookingStatus $to, $icon, string $color, string $action): Action
    {
        return Action::make($name)
            ->label(__('booking.admin.actions.'.$name))->icon($icon)->color($color)
            ->visible(fn (Booking $record) => BookingAccess::canManage() && $record->status->canTransitionTo($to))
            ->action(fn (Booking $record) => self::run(fn () => app($action)->handle($record), __('booking.admin.updated', ['status' => $to->getLabel()])));
    }

    public static function run(\Closure $callback, string $success): bool
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();

            return true;
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }
    }
}
