<?php

use App\Domain\Booking\Actions\CreateBooking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Events\BookingCheckedIn;
use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Actions\SendConsultationForm;
use App\Domain\Insights\Models\ConsultationForm;
use App\Domain\Insights\Models\OwnerAlert;
use App\Domain\Insights\Reports\ReportService;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Insights\Support\Period;
use App\Domain\Inventory\Actions\StockLedger;
use App\Domain\Marketing\Models\Review;
use App\Domain\Messaging\Channels\FakeChannel;
use App\Domain\Messaging\MessagingManager;
use App\Domain\Payments\Events\PaymentFailed;
use App\Domain\Payments\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/InsightsKit.php';

beforeEach(function () {
    MessagingManager::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00', 'Asia/Qatar'));
});

function signaturePng(): string
{
    $img = imagecreatetruecolor(300, 100);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imageline($img, 10, 80, 290, 20, imagecolorallocate($img, 28, 26, 23));
    ob_start();
    imagepng($img);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

it('sends a consultation form by link and stores the e-signature privately on the client', function () {
    $s = insightsSalon();
    $client = insightsClient($s, '+97455503001', 'Dana Ali', ['allergies' => 'Latex']);
    $this->actingAs($s->tenant->owner);
    $form = asTenant($s->tenant, fn () => app(SendConsultationForm::class)->handle($client));

    $sent = FakeChannel::sentTo('whatsapp', '+97455503001');
    expect($sent)->toHaveCount(1)->and($sent[0]['message']->body)->toContain('/consultation/'.$form->token)
        ->and($form->channel)->toBe('whatsapp');

    $this->get(tenantUrl($s->tenant, 'consultation/'.$form->token))->assertOk()->assertSee(__('insights.consultation.q.patch_test'))->assertSee('Dana');
    $this->get(tenantUrl($s->tenant, 'consultation/'.str_repeat('x', 48)))->assertNotFound();

    // Missing signature / consent is refused.
    $this->post(tenantUrl($s->tenant, 'consultation/'.$form->token), ['signed_name' => 'Dana Ali', 'signature' => 'data:image/png;base64,AAAA', 'answers' => ['consent' => '1']])
        ->assertSessionHasErrors('signature');

    $this->post(tenantUrl($s->tenant, 'consultation/'.$form->token), [
        'signed_name' => 'Dana Ali', 'signature' => signaturePng(),
        'answers' => ['patch_test' => 'yes', 'patch_test_date' => '2026-11-10', 'allergies' => 'PPD hair dye', 'pregnant' => '0', 'consent' => '1'],
    ])->assertRedirect('/consultation/'.$form->token);

    $form = asTenant($s->tenant, fn () => ConsultationForm::find($form->id));
    expect($form->status)->toBe('signed')->and($form->answers['allergies'])->toBe('PPD hair dye')->and($form->answers['pregnant'])->toBeFalse()
        ->and($form->signature_path)->toBe('consultations/'.$s->tenant->id.'/'.$form->id.'.png')
        ->and(asTenant($s->tenant, fn () => $client->fresh()->allergies))->toContain('PPD hair dye')->toContain('Latex')
        ->and(asTenant($s->tenant, fn () => $client->fresh()->consultationForms()->count()))->toBe(1);
    Storage::disk('local')->assertExists($form->signature_path);
    $this->assertFalse(Storage::disk('public')->exists($form->signature_path));

    $this->get(tenantUrl($s->tenant, 'dashboard/media/consultation-signatures/'.$form->id))->assertOk();
    $this->get(tenantUrl($s->tenant, 'dashboard/consultations'))->assertOk()->assertSee('Dana Ali');
    $this->post(tenantUrl($s->tenant, 'consultation/'.$form->token), ['signed_name' => 'X', 'signature' => signaturePng(), 'answers' => ['consent' => '1']])
        ->assertSessionHasErrors('signature'); // cannot sign twice
});

it('auto-sends the consultation form after a booking only when no valid signed form exists', function () {
    $s = insightsSalon();
    workSchedule($s, $s->staff[0], '10:00', '20:00');
    $client = insightsClient($s, '+97455503002', 'Huda');
    asTenant($s->tenant, function () use ($s, $client) {
        InsightSettings::put(['consultation' => ['auto_send' => true]]);
        foreach (['2026-11-19 11:00', '2026-11-20 11:00'] as $at) {
            app(CreateBooking::class)->handle(['branch' => $s->branch, 'items' => [['service_id' => $s->services['cut']->id]], 'client' => $client,
                'starts_at' => CarbonImmutable::parse($at, 'Asia/Qatar')->utc(), 'source' => 'dashboard']);
        }
        expect(ConsultationForm::count())->toBe(1); // open form re-used

        ConsultationForm::first()->update(['status' => 'signed', 'signed_at' => now()]);
        expect(SendConsultationForm::hasValidForm($client))->toBeTrue();
    });
});

it('hides other branches’ clients from branch staff when client sharing is off', function () {
    $s = insightsSalon();
    $main = insightsClient($s, '+97455504001', 'Main Maha', ['preferred_branch_id' => $s->branch->id]);
    $pearl = insightsClient($s, '+97455504002', 'Pearl Pari', ['preferred_branch_id' => $s->second->id]);
    $visitor = insightsClient($s, '+97455504003', 'Visitor Vera');
    $fresh = insightsClient($s, '+97455504004', 'Fresh Fay');
    insightsBooking($s, '2026-11-10 10:00', ['cut'], client: $visitor, branch: $s->second);
    insightsBooking($s, '2026-11-10 12:00', ['cut'], client: $main);

    $visible = fn () => asTenant($s->tenant, function () use ($s) {
        app(TenantContext::class)->restrictToBranches([$s->second->id]);

        return Client::query()->orderBy('name')->pluck('name')->all();
    });

    expect($visible())->toHaveCount(4);
    asTenant($s->tenant, fn () => InsightSettings::put(['client_sharing' => false]));
    expect($visible())->toBe(['Fresh Fay', 'Pearl Pari', 'Visitor Vera'])
        ->and(asTenant($s->tenant, fn () => Client::count()))->toBe(4); // owners / jobs are unaffected

    $manager = tenantUser($s->tenant, 'branch_manager', [$s->second->id]);
    $this->actingAs($manager)->get(tenantUrl($s->tenant, 'dashboard/clients'))->assertOk()->assertSee('Pearl Pari')->assertDontSee('Main Maha');
});

it('raises owner alerts with toggles: big sale, VIP check-in, bad review, low stock, failed payment', function () {
    $s = insightsSalon();
    $owner = $s->tenant->owner;
    $owner->update(['phone' => '+97455505000']);
    asTenant($s->tenant, fn () => InsightSettings::put(['alerts' => ['whatsapp' => true, 'big_sale_threshold' => 100000]]));

    insightsSale($s, '2026-11-18 11:00', [['service' => 'color', 'staff' => 0, 'total' => 150000]]);
    insightsSale($s, '2026-11-18 11:30', [['service' => 'cut', 'staff' => 0, 'total' => 20000]]); // below threshold

    $vip = insightsClient($s, '+97455505001', 'Vip Vanessa', ['is_vip' => true]);
    $booking = insightsBooking($s, '2026-11-18 13:00', ['cut'], client: $vip, status: BookingStatus::CheckedIn);
    asTenant($s->tenant, function () use ($s, $booking, $vip) {
        BookingCheckedIn::dispatch($booking);
        Review::create(['client_id' => $vip->id, 'rating' => 1, 'comment' => 'Waited 40 minutes', 'status' => 'private']);
        $ledger = app(StockLedger::class);
        $ledger->move($s->branch->id, $s->variant, 5, 'purchase');
        $ledger->move($s->branch->id, $s->variant, -3, 'sale'); // 5 → 2 crosses the threshold (3)
        $ledger->move($s->branch->id, $s->variant, -1, 'sale'); // 2 → 1 already low: no new alert
        $payment = Payment::create(['payable_type' => 'booking', 'payable_id' => $booking->id, 'method' => 'online', 'status' => 'failed', 'amount' => 25000, 'failure_reason' => 'Card declined']);
        PaymentFailed::dispatch($payment);
    });

    $types = asTenant($s->tenant, fn () => OwnerAlert::pluck('type')->sort()->values()->all());
    expect($types)->toBe(['bad_review', 'big_sale', 'failed_payment', 'low_stock', 'vip_checkin']);
    // Bell: everything except bad reviews (marketing already notifies low ratings).
    expect($owner->fresh()->notifications()->count())->toBe(4)
        ->and(collect($owner->fresh()->notifications)->pluck('data.alert_type')->sort()->values()->all())->toBe(['big_sale', 'failed_payment', 'low_stock', 'vip_checkin']);
    $whatsapp = collect(FakeChannel::sentTo('whatsapp', '+97455505000'))->pluck('message.body');
    expect($whatsapp)->toHaveCount(5)->and($whatsapp->implode("\n"))->toContain('Big sale')->toContain('Vip Vanessa')->toContain('Card declined');

    // Toggle off → silent.
    asTenant($s->tenant, fn () => InsightSettings::put(['alerts' => ['big_sale' => false]]));
    insightsSale($s, '2026-11-18 12:00', [['service' => 'color', 'staff' => 0, 'total' => 200000]]);
    expect(asTenant($s->tenant, fn () => OwnerAlert::where('type', 'big_sale')->count()))->toBe(1);
});

it('detects overbookings and renders the operations pages', function () {
    $s = insightsSalon();
    $a = insightsBooking($s, '2026-11-20 10:00', ['color'], $s->staff[0], BookingStatus::Confirmed); // 10:00–11:30
    $b = insightsBooking($s, '2026-11-20 11:00', ['cut'], $s->staff[0], BookingStatus::Confirmed);   // 11:00–12:00 overlaps 30 min
    insightsBooking($s, '2026-11-20 11:00', ['cut'], $s->staff[1], BookingStatus::Confirmed);        // other staff: fine

    $rows = asTenant($s->tenant, fn () => app(ReportService::class)->overbookings(Period::between('2026-11-18', '2026-11-30')));
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['staff' => 'Layla', 'a' => $a->reference, 'b' => $b->reference, 'overlap_minutes' => 30]);

    $this->actingAs($s->tenant->owner);
    $this->get(tenantUrl($s->tenant, 'dashboard/reports/overbookings'))->assertOk()->assertSee($b->reference);
});
