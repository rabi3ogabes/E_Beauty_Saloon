<?php

namespace App\Domain\AIStudio\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AICreditsLowNotification extends Notification
{
    use Queueable;

    public function __construct(public int $balance, public string $walletUrl, public ?string $invoiceNumber = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('ai_studio.wallet.low_subject'))
            ->line(__('ai_studio.wallet.low_line', ['balance' => number_format($this->balance)]));
        if ($this->invoiceNumber) {
            $mail->line(__('ai_studio.wallet.auto_topup_line', ['number' => $this->invoiceNumber]));
        }

        return $mail->action(__('ai_studio.wallet.buy_credits'), $this->walletUrl);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('ai_studio.wallet.low_subject'),
            'body' => __('ai_studio.wallet.low_line', ['balance' => number_format($this->balance)]),
            'format' => 'filament',
        ];
    }
}
