<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionSuspended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('salon.billing.suspended_subject'))
            ->line(__('salon.billing.suspended_line'))
            ->action(__('salon.billing.reactivate'), $this->subscription->tenant->url('locked'));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => __('salon.billing.suspended_subject')];
    }
}
