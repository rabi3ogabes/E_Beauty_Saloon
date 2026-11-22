<?php

namespace App\Domain\Insights\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Owner alert in the Filament notification bell (database channel). */
class OwnerAlertNotification extends Notification
{
    use Queueable;

    public const ICONS = [
        'big_sale' => 'heroicon-o-banknotes', 'vip_checkin' => 'heroicon-o-star', 'bad_review' => 'heroicon-o-face-frown',
        'low_stock' => 'heroicon-o-archive-box', 'failed_payment' => 'heroicon-o-credit-card',
    ];

    public function __construct(public string $type, public string $title, public string $body, public string $level = 'info') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $n = FilamentNotification::make()->title($this->title)->body($this->body)->icon(self::ICONS[$this->type] ?? 'heroicon-o-bell');

        $n = match ($this->level) {
            'success' => $n->success(),
            'warning' => $n->warning(),
            'danger' => $n->danger(),
            default => $n->info(),
        };

        return $n->getDatabaseMessage() + ['alert_type' => $this->type];
    }
}
