<?php

namespace App\Filament\Dashboard\Support;

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/** Dashboard booking form (new booking, calendar quick-create, walk-in) → CreateBooking. */
class BookingForm
{
    public static function schema(bool $withTime = true, bool $clientRequired = false): array
    {
        return array_values(array_filter([
            Grid::make(2)->schema([
                Select::make('branch_id')->label(__('salon.ui.branch'))->options(fn () => Branch::query()->active()->orderBy('sort')->get()->pluck('name', 'id'))
                    ->default(fn () => app(TenantContext::class)->branchId() ?? Branch::query()->active()->orderBy('sort')->value('id'))->required()->live(),
                Select::make('client_id')->label(__('booking.admin.client'))->searchable()->required($clientRequired)
                    ->getSearchResultsUsing(fn (string $search) => Client::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => $c->name.' · '.$c->phone]))
                    ->getOptionLabelUsing(fn ($value) => ($c = Client::find($value)) ? $c->name.' · '.$c->phone : null)
                    ->createOptionForm([
                        TextInput::make('name')->label(__('salon.ui.name'))->required()->maxLength(100),
                        TextInput::make('phone')->label(__('account.phone'))->tel()->required()->maxLength(20),
                    ])
                    ->createOptionUsing(fn (array $data) => Client::firstOrCreate(['phone' => Client::normalizePhone($data['phone'])], ['name' => $data['name'], 'source' => 'walk_in'])->id),
            ]),
            Repeater::make('items')->label(__('booking.admin.services'))->minItems(1)->maxItems(8)->defaultItems(1)->reorderable()->columns(2)
                ->schema([
                    Select::make('service_id')->label(__('booking.admin.service'))->required()->searchable()
                        ->options(fn () => Service::query()->active()->orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.$s->duration_minutes.' '.__('salon.ui.minutes')])),
                    Select::make('staff_id')->label(__('booking.admin.staff'))->placeholder(__('booking.flow.no_preference'))
                        ->options(fn () => Staff::query()->where('is_active', true)->orderBy('sort')->get()->pluck('name', 'id')),
                ]),
            $withTime ? DateTimePicker::make('starts_at')->label(__('booking.admin.starts_at'))->seconds(false)->minutesStep(5)->native(false)->required() : null,
            Textarea::make('internal_notes')->label(__('booking.admin.internal_notes'))->rows(2)->maxLength(1000),
        ]));
    }

    public static function create(array $data, string $source = 'dashboard', ?string $status = null, ?CarbonImmutable $start = null): Booking
    {
        $branch = Branch::findOrFail($data['branch_id']);
        $start ??= CarbonImmutable::parse($data['starts_at'], $branch->timezone);

        return app(CreateBooking::class)->handle([
            'branch' => $branch,
            'items' => collect($data['items'] ?? [])->values()->map(fn ($i) => ['service_id' => $i['service_id'] ?? null, 'staff_id' => $i['staff_id'] ?? null])->all(),
            'starts_at' => $start->utc(),
            'client_id' => $data['client_id'] ?? null,
            'source' => $source,
            'online' => false,
            'enforce_notice' => false,
            'payment_preference' => 'pay_at_salon',
            'internal_notes' => $data['internal_notes'] ?? null,
            'status' => $status,
        ]);
    }
}
