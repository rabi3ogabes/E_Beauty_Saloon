<?php

namespace App\Domain\Insights;

use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\Booking\Events\BookingCreated;
use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Listeners\OwnerAlertListeners;
use App\Domain\Insights\Listeners\SendConsultationOnBooking;
use App\Domain\Insights\Models\ConsultationForm;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\Capacity;
use App\Domain\Insights\Support\ClientBranchSharingScope;
use App\Domain\Inventory\Models\BranchStock;
use App\Domain\Marketing\Models\Review;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\POS\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Phase 9 — smart layer: command center, reports, AI insights and operations. */
class InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Capacity::class);
        $this->app->bind(ReportService::class);
    }

    public function boot(): void
    {
        // Consultation forms are attached to clients.
        Client::resolveRelationUsing('consultationForms', fn (Client $c) => $c->hasMany(ConsultationForm::class)->latest('id'));

        // Multi-branch client sharing toggle (no effect while sharing is on — the default).
        Client::addGlobalScope(new ClientBranchSharingScope);

        // Owner alerts.
        Event::listen(BookingCheckedIn::class, [OwnerAlertListeners::class, 'checkedIn']);
        Event::listen(PaymentFailed::class, [OwnerAlertListeners::class, 'paymentFailed']);
        Sale::saved(fn (Sale $sale) => DB::afterCommit(fn () => app(OwnerAlertListeners::class)->saleSaved($sale)));
        BranchStock::updated(fn (BranchStock $stock) => app(OwnerAlertListeners::class)->stockUpdated($stock));
        Review::created(fn (Review $review) => app(OwnerAlertListeners::class)->reviewCreated($review));

        // Opt-in consultation form after booking.
        Event::listen(BookingCreated::class, SendConsultationOnBooking::class);
    }
}
