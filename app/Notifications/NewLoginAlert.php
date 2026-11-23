<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewLoginAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $ip, public string $agent) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('security.new_login_subject'))
            ->line(__('security.new_login_line', ['ip' => $this->ip, 'time' => now()->toDayDateTimeString()]))
            ->line(Str::limit($this->agent, 120))
            ->line(__('security.not_you'));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => __('security.new_login_subject'), 'body' => __('security.new_login_line', ['ip' => $this->ip, 'time' => now()->toDayDateTimeString()]), 'format' => 'filament'];
    }
}
