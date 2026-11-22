<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Review;

/** Public/private reply to a review; optionally publishes it. */
class ReplyToReview
{
    public function handle(Review $review, string $reply, ?string $status = null): Review
    {
        abort_if(tenant()?->isReadOnly(), 423);
        $review->update(array_filter([
            'reply' => trim($reply),
            'replied_at' => now(),
            'replied_by' => auth()->id(),
            'status' => in_array($status, Review::STATUSES, true) ? $status : null,
        ], fn ($v) => $v !== null));
        activity()->performedOn($review)->log('review.replied');

        return $review;
    }
}
