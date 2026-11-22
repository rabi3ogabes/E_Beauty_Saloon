<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\BookingLock;
use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Group booking (bride + bridesmaids): one booking per member at the same start time, each member with
 * their own services/staff; all share group_id = lead booking id. All-or-nothing.
 *
 * $members: [['guest_name' => 'Bride', 'items' => [...], 'client' => ?Client], ...]  (first = lead)
 */
class CreateGroupBooking
{
    public function __construct(protected CreateBooking $create, protected BookingLock $lock) {}

    /** @return Collection<int, Booking> */
    public function handle(array $shared, array $members): Collection
    {
        if (count($members) < 2 || count($members) > 12) {
            throw new DomainException(__('booking.errors.group_size'));
        }
        $branch = $shared['branch'] ?? Branch::findOrFail($shared['branch_id']);

        return $this->lock->run((int) $branch->tenant_id, fn () => DB::transaction(function () use ($shared, $members, $branch) {
            $bookings = collect();
            $leadId = null;
            foreach (array_values($members) as $i => $member) {
                $items = array_map(fn ($item) => array_merge(['guest_name' => $member['guest_name'] ?? null], $item), $member['items'] ?? []);
                $booking = $this->create->handle(array_merge($shared, [
                    'branch' => $branch,
                    'items' => $items,
                    'client' => $member['client'] ?? ($shared['client'] ?? null),
                    'group_id' => $leadId,
                    'hold_token' => $i === 0 ? ($shared['hold_token'] ?? null) : null,
                ]));
                if ($i === 0) {
                    $leadId = $booking->id;
                    $booking->forceFill(['group_id' => $leadId])->save();
                }
                $bookings->push($booking);
            }

            return $bookings;
        }));
    }
}
