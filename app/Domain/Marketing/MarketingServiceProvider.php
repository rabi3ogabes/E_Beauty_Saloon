<?php

namespace App\Domain\Marketing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\AttributeBooking;
use App\Domain\Marketing\Actions\EarnLoyaltyForBooking;
use App\Domain\Marketing\Actions\RegisterReferral;
use App\Domain\Marketing\Actions\RewardReferral;
use App\Domain\Marketing\Automation\AutomationEngine;
use App\Domain\Marketing\Automation\TriggerRegistry;
use App\Domain\Marketing\Automation\TriggerScanners;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\ClientMembership;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Models\Review;
use App\Domain\Marketing\Services\ChannelResolver;
use App\Domain\Marketing\Support\AccountViews;
use App\Domain\Marketing\Support\MarketingUrl;
use App\Domain\Messaging\MessagingManager;
use App\Support\Account\AccountSections;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Marketing & CRM automation, packages/promotions, loyalty, referrals and reviews (modules "marketing" + "loyalty").
 */
class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TriggerRegistry::class);
        $this->app->singleton(ChannelResolver::class);
    }

    public function boot(): void
    {
        $this->registerChannels();
        $this->registerTriggers();
        $this->registerRelations();
        $this->registerObservers();
        $this->registerAccountSections();

        RateLimiter::for('marketing-sends', fn ($job) => Limit::perMinute(
            (int) (tenant()?->setting('marketing.rate_per_minute') ?? 600)
        )->by('marketing-sends:'.($job->tenantId ?? 'none')));
    }

    protected function registerChannels(): void
    {
        $messaging = $this->app->make(MessagingManager::class);
        foreach (['whatsapp', 'sms', 'email', 'push'] as $channel) {
            $messaging->extend($channel, fn ($tenant) => $this->app->make(ChannelResolver::class)->resolve($tenant, $channel));
        }
    }

    protected function registerTriggers(): void
    {
        $t = $this->app->make(TriggerRegistry::class);
        $label = fn (string $key) => fn () => __('marketing.automations.triggers.'.$key);

        $t->register('client_created', $label('client_created'));
        $t->register('booking_completed', $label('booking_completed'));
        $t->register('abandoned_cart', $label('abandoned_cart'));
        $t->register('booking_upcoming', $label('booking_upcoming'), TriggerScanners::bookingUpcoming(...), ['hours_before' => 24]);
        $t->register('rebook_due', $label('rebook_due'), TriggerScanners::rebookDue(...));
        $t->register('win_back', $label('win_back'), TriggerScanners::winBack(...), ['days' => 60]);
        $t->register('birthday', $label('birthday'), TriggerScanners::birthday(...));
        $t->register('low_wallet', $label('low_wallet'), TriggerScanners::lowWallet(...), ['below' => 50]);
        $t->register('package_expiring', $label('package_expiring'), TriggerScanners::packageExpiring(...), ['days_before' => 14]);
        $t->register('membership_renewal', $label('membership_renewal'), TriggerScanners::membershipRenewal(...), ['days_before' => 3]);
    }

    protected function registerRelations(): void
    {
        Client::resolveRelationUsing('clientPackages', fn (Client $c) => $c->hasMany(ClientPackage::class)->latest('id'));
        Client::resolveRelationUsing('clientMemberships', fn (Client $c) => $c->hasMany(ClientMembership::class)->latest('id'));
        Client::resolveRelationUsing('reviews', fn (Client $c) => $c->hasMany(Review::class)->latest('id'));
        Client::resolveRelationUsing('referralsMade', fn (Client $c) => $c->hasMany(Referral::class, 'referrer_client_id')->latest('id'));
        Client::resolveRelationUsing('campaignMessages', fn (Client $c) => $c->hasMany(CampaignMessage::class)->latest('id'));
        Booking::resolveRelationUsing('review', fn (Booking $b) => $b->hasOne(Review::class));
    }

    protected function registerObservers(): void
    {
        // Never return false from these listeners: Eloquent would cancel the save.
        Booking::creating(function (Booking $b): void {
            if (tenant()?->hasModule('marketing')) {
                app(AttributeBooking::class)->fill($b);
            }
        });
        Booking::created(function (Booking $b): void {
            if (tenant()?->hasModule('marketing')) {
                app(AttributeBooking::class)->link($b);
            }
        });

        Booking::updated(function (Booking $booking) {
            if (! tenant() || ! $booking->wasChanged(['status', 'total', 'campaign_id'])) {
                return;
            }
            if (tenant()->hasModule('marketing')) {
                app(AttributeBooking::class)->link($booking);
            }
            if ($booking->wasChanged('status') && $booking->status === BookingStatus::Completed) {
                app(EarnLoyaltyForBooking::class)->handle($booking);
                app(RewardReferral::class)->handle($booking);
                if ($booking->client_id && ($client = Client::find($booking->client_id))) {
                    app(AutomationEngine::class)->fire('booking_completed', $client, [
                        'dedupe' => 'booking:'.$booking->id, 'reference' => $booking->reference,
                        'review_url' => MarketingUrl::to('/review/'.$booking->reference),
                    ]);
                }
            }
        });

        Client::created(function (Client $client) {
            if (! tenant()) {
                return;
            }
            if (tenant()->hasModule('loyalty') && request()->hasSession() && ($code = request()->session()->pull('marketing.referral_code'))) {
                app(RegisterReferral::class)->handle($client, $code);
            }
            app(AutomationEngine::class)->fire('client_created', $client, ['dedupe' => 'welcome']);
        });
    }

    protected function registerAccountSections(): void
    {
        $sections = $this->app->make(AccountSections::class);
        $sections->register('packages', fn () => __('marketing.account.packages'), 'gift', fn ($client) => AccountViews::packages($client), order: 40, module: 'marketing');
        $sections->register('loyalty', fn () => __('marketing.account.loyalty'), 'star', fn ($client) => AccountViews::loyalty($client), order: 45, module: 'loyalty');
        $sections->register('referrals', fn () => __('marketing.account.referrals'), 'users', fn ($client) => AccountViews::referrals($client), order: 46, module: 'loyalty');
    }
}
