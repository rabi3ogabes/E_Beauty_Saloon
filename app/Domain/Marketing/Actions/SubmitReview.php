<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Booking\Models\Booking;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Notifications\LowRatingReceived;
use App\Domain\Messaging\DTOs\OutboundMessage;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Team\Models\Staff;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Post-visit review. Ratings ≥ 4 are published on the website; ≤ 3 stay private and are routed to the owner
 * (database notification + WhatsApp) so the salon can recover the client. Updates the staff member's rating.
 */
class SubmitReview
{
    public function __construct(protected MessagingManager $messaging) {}

    public function handle(Booking $booking, int $rating, ?string $comment = null, string $source = 'website'): Review
    {
        if ($rating < 1 || $rating > 5) {
            throw new DomainException(__('marketing.reviews.errors.rating'));
        }
        if (Review::where('booking_id', $booking->id)->exists()) {
            throw new DomainException(__('marketing.reviews.errors.already'));
        }

        $review = DB::transaction(function () use ($booking, $rating, $comment, $source) {
            $staffId = $booking->items()->whereNotNull('staff_id')->value('staff_id');
            $review = Review::create([
                'client_id' => $booking->client_id, 'booking_id' => $booking->id, 'branch_id' => $booking->branch_id, 'staff_id' => $staffId,
                'rating' => $rating, 'comment' => $comment ? mb_substr(trim($comment), 0, 2000) : null,
                'status' => $rating >= Review::PUBLISH_THRESHOLD ? 'published' : 'private', 'source' => $source,
            ]);
            if ($staffId && ($staff = Staff::withoutGlobalScopes()->find($staffId))) {
                $count = (int) $staff->ratings_count;
                $staff->forceFill([
                    'rating' => round((((float) $staff->rating * $count) + $rating) / ($count + 1), 2),
                    'ratings_count' => $count + 1,
                ])->save();
            }

            return $review;
        });

        if ($review->status === 'private') {
            $this->routeToOwner($review);
        }

        return $review;
    }

    protected function routeToOwner(Review $review): void
    {
        $tenant = tenant();
        $owner = $tenant?->owner;
        if ($owner) {
            $owner->notify(new LowRatingReceived($review));
        }
        $phone = $owner?->phone ?: $tenant?->phone;
        if ($phone) {
            $this->messaging->send('whatsapp', new OutboundMessage($phone, __('marketing.reviews.owner_alert', [
                'rating' => $review->rating, 'client' => $review->client?->name ?? '—', 'comment' => $review->comment ?: '—',
            ], $owner?->locale ?? 'en'), meta: ['review_id' => $review->id]));
        }
    }
}
