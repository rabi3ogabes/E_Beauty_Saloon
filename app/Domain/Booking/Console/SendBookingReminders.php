<?php

namespace App\Domain\Booking\Console;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Notifications\BookingNotifier;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 5 minutes: 24h and 2h reminders for confirmed bookings, and the post-visit thank-you
 * (review link + rebook suggestion) ~2h after completion. Idempotent via reminder_*_sent_at /
 * review_requested_at, which are claimed atomically before sending.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'booking:reminders {--tenant= : Only this tenant id}';

    protected $description = 'Send booking reminders (24h, 2h) and post-visit thank-you messages';

    public function handle(TenantContext $context, BookingNotifier $notifier): int
    {
        $sent = 0;
        Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get()
            ->filter(fn (Tenant $t) => $t->hasModule('booking'))
            ->each(function (Tenant $tenant) use ($context, $notifier, &$sent) {
                $context->run($tenant, function () use ($notifier, &$sent) {
                    $sent += $this->remind($notifier, 'reminder_24h_sent_at', 'reminder_24h', now()->addHours(2), now()->addHours(24));
                    $sent += $this->remind($notifier, 'reminder_2h_sent_at', 'reminder_2h', now(), now()->addHours(2));
                    $sent += $this->thankYou($notifier);
                });
            });

        $this->info("Booking messages sent: {$sent}");

        return self::SUCCESS;
    }

    protected function remind(BookingNotifier $notifier, string $column, string $type, $from, $to): int
    {
        $count = 0;
        Booking::query()->where('status', BookingStatus::Confirmed->value)->whereNotNull('client_id')
            ->whereNull($column)->where('starts_at', '>', $from)->where('starts_at', '<=', $to)
            ->when($type === 'reminder_24h', fn ($q) => $q->where('created_at', '<', now()->subHours(2))) // they just got the confirmation
            ->with(['client', 'branch', 'items.service', 'items.staff'])->orderBy('starts_at')->limit(500)->get()
            ->each(function (Booking $booking) use ($notifier, $column, $type, &$count) {
                // Claim first so overlapping runs can never send twice.
                $claimed = Booking::withoutGlobalScopes()->whereKey($booking->id)->whereNull($column)->update([$column => now()]);
                if ($claimed && $notifier->send($booking, $type) !== null) {
                    $count++;
                }
                // A 24h reminder that comes due inside the 2h window is superseded by the 2h one.
                if ($type === 'reminder_2h' && ! $booking->reminder_24h_sent_at) {
                    Booking::withoutGlobalScopes()->whereKey($booking->id)->whereNull('reminder_24h_sent_at')->update(['reminder_24h_sent_at' => now()]);
                }
            });

        return $count;
    }

    protected function thankYou(BookingNotifier $notifier): int
    {
        $count = 0;
        Booking::query()->where('status', BookingStatus::Completed->value)->whereNotNull('client_id')->whereNull('review_requested_at')
            ->where('completed_at', '<=', now()->subHours(2))->where('completed_at', '>', now()->subDays(3))
            ->with(['client', 'branch', 'items.service', 'items.staff'])->limit(500)->get()
            ->each(function (Booking $booking) use ($notifier, &$count) {
                if (Booking::withoutGlobalScopes()->whereKey($booking->id)->whereNull('review_requested_at')->update(['review_requested_at' => now()])
                    && $notifier->send($booking, 'thank_you') !== null) {
                    $count++;
                }
            });

        return $count;
    }
}
