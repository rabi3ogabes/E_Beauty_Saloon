<?php

namespace Tests\Feature\Marketing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Segment;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/** Small factories shared by the Marketing feature tests (call inside asTenant()). */
final class MarketingFixtures
{
    public static function client(array $attrs = [], array $consents = ['whatsapp', 'sms', 'email']): Client
    {
        static $n = 0;
        $n++;
        $client = Client::create(array_merge([
            'name' => 'Client '.$n.' Test', 'phone' => '+9745500'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => 'client'.$n.'@example.com', 'locale' => 'en',
        ], $attrs));
        foreach ($consents as $channel) {
            $client->setConsent($channel, true, 'test');
        }

        return $client->fresh();
    }

    public static function service(int $price = 15000, ?int $rebook = null): Service
    {
        $cat = ServiceCategory::firstOrCreate(['slug' => 'hair'], ['name' => ['en' => 'Hair', 'ar' => 'الشعر']]);

        return Service::create([
            'service_category_id' => $cat->id, 'name' => ['en' => 'Blow-dry '.uniqid(), 'ar' => 'استشوار'], 'slug' => 'svc-'.uniqid(),
            'duration_minutes' => 45, 'price' => $price, 'rebook_after_days' => $rebook,
        ]);
    }

    public static function booking(Client $client, array $attrs = []): Booking
    {
        $branch = Branch::first();

        return Booking::create(array_merge([
            'branch_id' => $branch->id, 'client_id' => $client->id, 'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'subtotal' => 20000, 'total' => 20000,
        ], $attrs));
    }

    public static function segment(array $conditions = [], string $match = 'all'): Segment
    {
        return Segment::create(['name' => 'Audience '.uniqid(), 'rules' => ['match' => $match, 'conditions' => $conditions]]);
    }

    public static function campaign(Segment $segment, array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'name' => 'Spring glow', 'segment_id' => $segment->id, 'channels' => ['whatsapp', 'sms'],
            'content' => ['en' => ['subject' => 'Spring glow', 'body' => 'Hi {first_name}, book your glow: {link}'], 'ar' => ['body' => 'مرحباً {first_name} {link}']],
        ], $attrs));
    }

    /** Quiet hours off so sends happen immediately regardless of the clock. */
    public static function noQuietHours(Tenant $tenant): Tenant
    {
        $tenant->update(['settings' => array_replace_recursive($tenant->settings ?? [], ['marketing' => ['quiet_hours' => ['enabled' => false]]])]);
        $fresh = $tenant->fresh();
        app(TenantContext::class)->set($fresh);

        return $fresh;
    }
}
