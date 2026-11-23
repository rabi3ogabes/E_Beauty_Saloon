<?php

namespace App\Filament\Dashboard\Resources\Bookings\Pages;

use App\Domain\Booking\Actions\CreateGroupBooking;
use App\Domain\Booking\Actions\CreateRecurringBookings;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Pages\StaffCalendar;
use App\Filament\Dashboard\Resources\Bookings\BookingResource;
use App\Filament\Dashboard\Support\BookingAccess;
use App\Filament\Dashboard\Support\BookingActions;
use App\Filament\Dashboard\Support\BookingForm;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')->label(__('booking.calendar_page.title'))->icon(Heroicon::OutlinedViewColumns)->color('gray')->url(fn () => StaffCalendar::getUrl()),
            ActionGroup::make([$this->groupAction(), $this->recurringAction()])->label(__('booking.admin.more'))->icon(Heroicon::OutlinedEllipsisHorizontal)->color('gray')->button()
                ->visible(fn () => BookingAccess::canManage()),
            Action::make('newBooking')->label(__('booking.admin.new'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => BookingAccess::canManage())
                ->schema(BookingForm::schema())
                ->action(function (array $data) {
                    BookingActions::run(fn () => BookingForm::create($data), __('booking.admin.created'));
                }),
        ];
    }

    /** Recurring: the same services every N weeks. */
    protected function recurringAction(): Action
    {
        return Action::make('recurring')->label(__('booking.admin.recurring'))->icon(Heroicon::OutlinedArrowPath)
            ->schema(array_merge(BookingForm::schema(), [
                Grid::make(2)->schema([
                    TextInput::make('every_weeks')->label(__('booking.admin.every_weeks'))->numeric()->minValue(1)->maxValue(12)->default(4)->required(),
                    TextInput::make('count')->label(__('booking.admin.occurrences'))->numeric()->minValue(2)->maxValue(26)->default(6)->required(),
                ]),
            ]))
            ->action(function (array $data) {
                $branch = Branch::findOrFail($data['branch_id']);
                BookingActions::run(function () use ($data, $branch) {
                    $result = app(CreateRecurringBookings::class)->handle([
                        'branch' => $branch, 'source' => 'dashboard', 'online' => false, 'enforce_notice' => false, 'payment_preference' => 'pay_at_salon',
                        'items' => collect($data['items'])->values()->map(fn ($i) => ['service_id' => $i['service_id'], 'staff_id' => $i['staff_id'] ?? null])->all(),
                        'starts_at' => CarbonImmutable::parse($data['starts_at'], $branch->timezone)->utc(), 'client_id' => $data['client_id'] ?? null,
                        'internal_notes' => $data['internal_notes'] ?? null,
                    ], (int) $data['every_weeks'], (int) $data['count']);
                    if ($result['skipped']) {
                        Notification::make()->warning()->title(__('booking.admin.recurring_skipped', ['dates' => implode(', ', $result['skipped'])]))->send();
                    }
                }, __('booking.admin.created'));
            });
    }

    /** Group booking (bride + bridesmaids): one booking per guest at the same time, sharing a group id. */
    protected function groupAction(): Action
    {
        return Action::make('group')->label(__('booking.admin.group'))->icon(Heroicon::OutlinedUserGroup)
            ->schema([
                ...array_slice(BookingForm::schema(), 0, 1), // branch + client (lead)
                DateTimePicker::make('starts_at')->label(__('booking.admin.starts_at'))->seconds(false)->minutesStep(5)->native(false)->required(),
                Repeater::make('members')->label(__('booking.admin.guests'))->minItems(2)->maxItems(12)->defaultItems(2)->columns(3)
                    ->schema([
                        TextInput::make('guest_name')->label(__('salon.ui.name'))->required()->maxLength(100),
                        Select::make('service_id')->label(__('booking.admin.service'))->required()->searchable()
                            ->options(fn () => Service::query()->active()->orderBy('sort')->get()->pluck('name', 'id')),
                        Select::make('staff_id')->label(__('booking.admin.staff'))->placeholder(__('booking.flow.no_preference'))
                            ->options(fn () => Staff::query()->where('is_active', true)->orderBy('sort')->get()->pluck('name', 'id')),
                    ]),
            ])
            ->action(function (array $data) {
                $branch = Branch::findOrFail($data['branch_id']);
                BookingActions::run(fn () => app(CreateGroupBooking::class)->handle(
                    ['branch' => $branch, 'source' => 'dashboard', 'online' => false, 'enforce_notice' => false, 'payment_preference' => 'pay_at_salon',
                        'starts_at' => CarbonImmutable::parse($data['starts_at'], $branch->timezone)->utc(), 'client_id' => $data['client_id'] ?? null],
                    collect($data['members'])->values()->map(fn ($m) => ['guest_name' => $m['guest_name'], 'items' => [['service_id' => $m['service_id'], 'staff_id' => $m['staff_id'] ?? null]]])->all(),
                ), __('booking.admin.created'));
            });
    }
}
