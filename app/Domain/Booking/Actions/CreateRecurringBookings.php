<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * Recurring bookings: same services every N weeks, $count occurrences (e.g. every 4 weeks × 6).
 * Occurrences that cannot be booked are skipped and reported; the first one must succeed.
 *
 * @return array{bookings: list<Booking>, skipped: list<string>}
 */
class CreateRecurringBookings
{
    public function __construct(protected CreateBooking $create) {}

    public function handle(array $data, int $everyWeeks, int $count): array
    {
        if ($everyWeeks < 1 || $everyWeeks > 12 || $count < 2 || $count > 26) {
            throw new DomainException(__('booking.errors.recurrence'));
        }
        $branch = $data['branch'] ?? Branch::findOrFail($data['branch_id']);
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $rule = "every:{$everyWeeks}:weeks:count:{$count}";
        // Keep the local wall-clock time across DST changes.
        $local = $start->setTimezone($branch->timezone);

        $first = $this->create->handle(array_merge($data, ['branch' => $branch, 'recurrence_rule' => $rule]));
        $bookings = [$first];
        $skipped = [];
        for ($k = 1; $k < $count; $k++) {
            $at = $local->addWeeks($everyWeeks * $k);
            try {
                $bookings[] = $this->create->handle(array_merge($data, [
                    'branch' => $branch, 'starts_at' => $at->utc(), 'recurrence_rule' => $rule, 'recurring_parent_id' => $first->id, 'hold_token' => null,
                ]));
            } catch (DomainException) {
                $skipped[] = $at->format('Y-m-d H:i');
            }
        }

        return ['bookings' => $bookings, 'skipped' => $skipped];
    }
}
