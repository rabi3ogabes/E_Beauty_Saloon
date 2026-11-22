<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Marketing\Actions\SubmitReview;
use App\Domain\Marketing\Models\Review;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\BranchScope;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    protected function booking(string $reference): Booking
    {
        return Booking::withoutGlobalScope(BranchScope::class)->with(['client', 'items.staff', 'items.service'])
            ->where('reference', strtoupper($reference))->firstOrFail();
    }

    public function show(string $reference)
    {
        $booking = $this->booking($reference);

        return view('marketing.site.review', ['booking' => $booking, 'review' => Review::where('booking_id', $booking->id)->first()]);
    }

    public function store(Request $request, string $reference, SubmitReview $submit)
    {
        $booking = $this->booking($reference);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);
        if (! Review::where('booking_id', $booking->id)->exists()) {
            $submit->handle($booking, (int) $data['rating'], $data['comment'] ?? null);
        }

        return redirect()->route('marketing.review.show', $booking->reference);
    }
}
