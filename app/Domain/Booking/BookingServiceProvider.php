<?php

namespace App\Domain\Booking;

use App\Domain\Booking\Availability\AvailabilityCache;
use App\Domain\Booking\Console\BookingHousekeeping;
use App\Domain\Booking\Console\SendBookingReminders;
use App\Domain\Booking\Listeners\BookingLifecycleSubscriber;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\BookingItem;
use App\Domain\Booking\Models\SlotHold;
use App\Domain\Booking\Models\WaitlistEntry;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchHoliday;
use App\Domain\Branches\Models\Resource;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\StaffSchedule;
use App\Domain\Team\Models\TimeOff;
use App\Http\Controllers\Site\Booking\AccountBookingsSection;
use App\Support\Account\AccountSections;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AvailabilityCache::class);
    }

    public function boot(): void
    {
        // Relations onto core models.
        Client::resolveRelationUsing('bookings', fn (Client $client) => $client->hasMany(Booking::class));
        Staff::resolveRelationUsing('bookingItems', fn (Staff $staff) => $staff->hasMany(BookingItem::class));
        Branch::resolveRelationUsing('bookings', fn (Branch $branch) => $branch->hasMany(Booking::class));
        WaitlistEntry::resolveRelationUsing('staff', fn (WaitlistEntry $e) => $e->belongsTo(Staff::class));

        Event::subscribe(BookingLifecycleSubscriber::class);
        $this->registerCacheInvalidation();

        app(AccountSections::class)->register('bookings', fn () => __('booking.account.title'), 'calendar',
            fn ($client, $request) => app(AccountBookingsSection::class)->render($client, $request), order: 10, module: 'booking');

        try {
            Broadcast::channel('tenant.{tenantId}.bookings', fn ($user, $tenantId) => (int) $user->tenant_id === (int) $tenantId && $user->can('bookings.view'));
        } catch (\Throwable) {
            // broadcasting not configured — calendars fall back to polling
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SendBookingReminders::class, BookingHousekeeping::class]);
        }
    }

    /** Availability is cached per (tenant, branch) version; bump it whenever calendar inputs change. */
    protected function registerCacheInvalidation(): void
    {
        $cache = fn () => app(AvailabilityCache::class);

        $booking = function (Booking $b) use ($cache) {
            $cache()->bump($b->tenant_id, $b->branch_id);
            $cache()->bumpForStaff($b->tenant_id, BookingItem::where('booking_id', $b->id)->pluck('staff_id')->all());
        };
        Booking::saved($booking);
        Booking::deleted($booking);

        $item = function (BookingItem $i) use ($cache) {
            $b = Booking::withoutGlobalScopes()->select(['id', 'tenant_id', 'branch_id'])->find($i->booking_id);
            if ($b) {
                $cache()->bump($b->tenant_id, $b->branch_id);
                $cache()->bumpForStaff($b->tenant_id, array_filter([$i->staff_id, $i->getOriginal('staff_id')]));
            }
        };
        BookingItem::saved($item);
        BookingItem::deleted($item);

        $hold = fn (SlotHold $h) => [$cache()->bump($h->tenant_id, $h->branch_id), $cache()->bumpForStaff($h->tenant_id, $h->staff_id)];
        SlotHold::saved($hold);
        SlotHold::deleted($hold);

        $staffChange = fn ($m) => $cache()->bumpForStaff($m->tenant_id, $m->staff_id);
        foreach ([StaffSchedule::class, TimeOff::class] as $model) {
            $model::saved($staffChange);
            $model::deleted($staffChange);
        }
        Staff::saved(fn (Staff $s) => $cache()->bumpForStaff($s->tenant_id, $s->id));

        $branchChange = fn ($m) => $cache()->bump($m->tenant_id, $m->branch_id);
        foreach ([BranchHoliday::class, Resource::class] as $model) {
            $model::saved($branchChange);
            $model::deleted($branchChange);
        }
        Branch::saved(fn (Branch $b) => $cache()->bump($b->tenant_id, $b->id));
        Service::saved(fn ($s) => $cache()->bumpTenant($s->tenant_id));
    }
}
