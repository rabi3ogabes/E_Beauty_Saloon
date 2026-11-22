<?php

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Availability\LineBuilder;
use App\Domain\Booking\Availability\ServiceLine;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Domain\Team\Models\Staff;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Shared fixtures for booking tests (required from each test file).
 * Default salon: branch "main" in Asia/Qatar open 10:00–22:00 every day, slot interval 15, min notice 60.
 */

if (! function_exists('bookingSalon')) {
    function bookingSalon(array $opts = []): object
    {
        $tenant = createTenant($opts['plan'] ?? 'maison', slug: $opts['slug'] ?? null);
        $settings = array_merge($tenant->settings['booking'] ?? [], $opts['settings'] ?? []);
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['booking' => $settings])]);
        $tenant = $tenant->fresh();

        return asTenant($tenant, function () use ($tenant, $opts) {
            $branch = Branch::where('slug', 'main')->first();
            $branch->update(['timezone' => $opts['timezone'] ?? 'Asia/Qatar', 'opening_hours' => $opts['hours'] ?? Branch::defaultHours()]);

            $category = ServiceCategory::create(['name' => ['en' => 'Hair', 'ar' => 'الشعر'], 'slug' => 'hair']);
            $mk = fn (string $name, int $minutes, int $price, array $extra = []) => tap(Service::create(array_merge([
                'service_category_id' => $category->id, 'name' => ['en' => $name, 'ar' => $name.' ع'], 'slug' => Str::slug($name),
                'duration_minutes' => $minutes, 'price' => $price,
            ], $extra)), fn ($s) => $s->branches()->attach($branch->id, ['is_active' => true]));

            $services = [
                'cut' => $mk('Cut', 60, 20000),
                'color' => $mk('Color', 90, 40000),
                'blowdry' => $mk('Blow dry', 30, 10000),
            ];

            $staff = [];
            foreach (($opts['staff'] ?? ['Layla', 'Sofia']) as $i => $name) {
                $s = Staff::create(['name' => ['en' => $name, 'ar' => $name], 'sort' => $i, 'color' => '#C8A96A']);
                $s->branches()->attach($branch->id);
                $s->services()->attach(collect($services)->pluck('id'));
                $staff[] = $s;
            }

            return (object) ['tenant' => $tenant, 'branch' => $branch->fresh(), 'services' => $services, 'staff' => $staff, 'category' => $category];
        });
    }

    /** Schedule a staff member at the salon branch: weekdays 0..6, times local. */
    function workSchedule(object $salon, Staff $staff, string $from = '10:00', string $to = '20:00', array $breaks = [], ?array $weekdays = null): void
    {
        asTenant($salon->tenant, function () use ($salon, $staff, $from, $to, $breaks, $weekdays) {
            foreach ($weekdays ?? range(0, 6) as $wd) {
                $staff->schedules()->create(['tenant_id' => $salon->tenant->id, 'branch_id' => $salon->branch->id, 'weekday' => $wd, 'starts_at' => $from, 'ends_at' => $to, 'breaks' => $breaks]);
            }
        });
    }

    /** @return list<ServiceLine> */
    function lines(object $salon, array $items, bool $online = true): array
    {
        return asTenant($salon->tenant, fn () => app(LineBuilder::class)->build($salon->branch, array_map(
            fn ($i) => is_string($i) ? ['service_id' => $salon->services[$i]->id] : array_merge($i, ['service_id' => $salon->services[$i['service']]->id]),
            $items,
        ), $online));
    }

    function engine(): AvailabilityEngine
    {
        return app(AvailabilityEngine::class);
    }

    /** Local times ("H:i") of the slots of a day. */
    function slotTimes(object $salon, array $items, string $date, array $options = []): array
    {
        return asTenant($salon->tenant, function () use ($salon, $items, $date, $options) {
            $day = engine()->day($salon->branch, lines($salon, $items), $date, $options);

            return array_map(fn ($s) => $s->startsAt()->setTimezone($salon->branch->timezone)->format('H:i'), $day->slots);
        });
    }

    function bookingClient(Tenant $tenant, string $phone = '+97455550001', string $name = 'Aisha'): Client
    {
        return asTenant($tenant, fn () => Client::create(['name' => $name, 'phone' => $phone, 'email' => Str::slug($name).'@example.com']));
    }

    function onlineGateway(Tenant $tenant, array $for = ['booking', 'deposits']): TenantPaymentGateway
    {
        return asTenant($tenant, fn () => TenantPaymentGateway::create(['driver' => 'tap', 'label' => 'Tap', 'enabled_for' => $for, 'is_active' => true, 'mode' => 'test']));
    }

    /** UTC Carbon for a local branch time. */
    function atLocal(object $salon, string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, $salon->branch->timezone)->utc();
    }
}
