<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription, public Invoice $invoice, public int $days) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('salon.billing.reminder_subject', ['days' => $this->days]))
            ->greeting(__('salon.billing.hello', ['name' => $notifiable->name]))
            ->line(__('salon.billing.reminder_line', ['days' => $this->days, 'date' => $this->subscription->ends_at->toFormattedDayDateString()]))
            ->line(__('salon.billing.amount_due', ['amount' => money($this->invoice->total, $this->invoice->currency), 'number' => $this->invoice->number]))
            ->action(__('salon.billing.pay_now'), $this->subscription->tenant->url('dashboard/billing'));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => __('salon.billing.reminder_subject', ['days' => $this->days]), 'invoice_id' => $this->invoice->id];
    }
}
