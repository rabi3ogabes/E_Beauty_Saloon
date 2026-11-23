<?php

namespace App\Domain\Marketing\Notifications;

use App\Domain\Marketing\Models\Review;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Database notification (Filament bell) for a private ≤ 3★ review. */
class LowRatingReceived extends Notification
{
    use Queueable;

    public function __construct(public Review $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('marketing.reviews.low_rating_title', ['rating' => $this->review->rating]))
            ->body(trim(($this->review->client?->name ?? '').' — '.($this->review->comment ?? '')))
            ->icon('heroicon-o-exclamation-triangle')
            ->warning()
            ->getDatabaseMessage();
    }
}
