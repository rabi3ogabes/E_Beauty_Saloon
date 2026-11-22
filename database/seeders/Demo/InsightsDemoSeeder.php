<?php

namespace Database\Seeders\Demo;

use App\Domain\Assistant\Models\Conversation;
use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\CRM\Models\Client;
use App\Domain\Insights\Actions\GenerateInsights;
use App\Domain\Insights\Models\ConsultationForm;
use App\Domain\Insights\Models\InsightRun;
use App\Domain\Insights\Models\OwnerAlert;
use App\Domain\Insights\Notifications\OwnerAlertNotification;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Smart-layer demo for "lumiere": settings (targets, morning report, receptionist), this week's insight cards
 * (template copy — no AI calls while seeding), WhatsApp inbox conversations, consultation forms and owner alerts.
 * Idempotent: skipped when the tenant already has an insight run.
 */
class InsightsDemoSeeder extends Seeder
{
    public static int $order = 80;

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'lumiere')->first();
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            if (InsightRun::query()->exists()) {
                return;
            }
            $branches = Branch::query()->orderBy('sort')->get();

            InsightSettings::put([
                'ai_copy' => false, // seed with templates, never call providers from a seeder
                'morning_report' => ['enabled' => true, 'email' => true, 'whatsapp' => false, 'time' => '08:00'],
                'targets' => ['daily_default' => 450000, 'daily' => $branches->mapWithKeys(fn ($b, $i) => [$b->id => $i === 0 ? 600000 : 350000])->all()],
                'alerts' => ['whatsapp' => false, 'big_sale_threshold' => 150000],
            ]);
            AssistantSettings::put([
                'enabled' => true, 'hours_from' => '09:00', 'hours_to' => '23:00',
                'faq' => "Free valet parking at both branches.\nWe accept card, cash and Apple Pay.\nBridal parties: ask for our private suite.\nCancellations are free up to 24 hours before.",
            ]);

            app(GenerateInsights::class)->handle($tenant);
            InsightSettings::put(['ai_copy' => true]);

            $this->conversations($branches->first());
            $this->consultations();
            $this->alerts($tenant);
        });
    }

    protected function conversations(?Branch $branch): void
    {
        $booking = Booking::query()->where('source', 'whatsapp_ai')->where('starts_at', '>', now())->with(['client', 'items.service'])->first()
            ?? Booking::query()->where('starts_at', '>', now())->whereNotNull('client_id')->with(['client', 'items.service'])->first();
        if ($booking?->client) {
            $c = Conversation::create(['phone' => $booking->client->phone, 'client_id' => $booking->client_id, 'contact_name' => $booking->client->name,
                'locale' => 'en', 'status' => 'bot', 'last_booking_id' => $booking->id, 'last_message_at' => now()->subHours(3)]);
            $local = $booking->starts_at->copy()->setTimezone($branch?->timezone ?? 'Asia/Qatar');
            $service = (string) $booking->items->first()?->service?->name;
            $c->messages()->create(['direction' => 'in', 'author' => 'client', 'body' => "Hi! Do you have time for a {$service} on ".$local->format('l').'?', 'created_at' => now()->subHours(3)]);
            $c->messages()->create(['direction' => 'out', 'author' => 'bot', 'confidence' => 0.94, 'created_at' => now()->subHours(3)->addMinute(),
                'body' => "You're booked for {$service} on ".$local->translatedFormat('l j M').' at '.$local->format('H:i').'. See you soon ✨',
                'tool_calls' => [
                    ['name' => 'check_availability', 'arguments' => ['service_id' => $booking->items->first()?->service_id, 'date' => $local->toDateString()], 'result' => ['times' => [$local->format('H:i')]]],
                    ['name' => 'book', 'arguments' => ['time' => $local->format('H:i')], 'result' => ['booked' => true, 'reference' => $booking->reference]],
                ]]);
        }

        $c = Conversation::create(['phone' => '+97455012345', 'contact_name' => 'نورة', 'locale' => 'ar', 'status' => 'handoff', 'handoff_reason' => 'requested',
            'unread_count' => 2, 'last_message_at' => now()->subMinutes(20)]);
        $c->messages()->create(['direction' => 'in', 'author' => 'client', 'body' => 'مرحبا، عندي حفلة زواج الشهر القادم وأبغى باقة عروس لـ ٦ أشخاص', 'created_at' => now()->subMinutes(25)]);
        $c->messages()->create(['direction' => 'in', 'author' => 'client', 'body' => 'ممكن أتكلم مع موظفة؟', 'created_at' => now()->subMinutes(21)]);
        $c->messages()->create(['direction' => 'out', 'author' => 'bot', 'body' => __('insights.assistant.handoff_reply', ['salon' => tenant()->name], 'ar'), 'created_at' => now()->subMinutes(20)]);
    }

    protected function consultations(): void
    {
        $clients = Client::query()->where('visits_count', '>', 0)->orderByDesc('lifetime_value')->limit(2)->get();
        foreach ($clients as $i => $client) {
            $form = ConsultationForm::create(['client_id' => $client->id, 'token' => Str::random(48), 'locale' => $client->locale ?: 'en',
                'channel' => 'whatsapp', 'sent_at' => now()->subDays(3 - $i), 'expires_at' => now()->addDays(11 + $i)]);
            if ($i === 0) {
                $path = 'consultations/'.$form->tenant_id.'/'.$form->id.'.png';
                Storage::disk('local')->put($path, $this->signaturePng());
                $form->update(['status' => 'signed', 'signed_at' => now()->subDays(2), 'signed_name' => $client->name, 'signature_path' => $path,
                    'answers' => ['patch_test' => 'yes', 'patch_test_date' => now()->subDays(10)->toDateString(), 'allergies' => null, 'medications' => null,
                        'pregnant' => false, 'skin_conditions' => 'Sensitive scalp', 'notes' => 'Prefers fragrance-free products', 'consent' => true]]);
            }
        }
    }

    protected function alerts(Tenant $tenant): void
    {
        $owner = $tenant->owner;
        $vip = Client::query()->where('is_vip', true)->first();
        $items = [
            ['big_sale', 'demo:big_sale', ['amount' => money(186000), 'branch' => (string) Branch::query()->orderBy('sort')->first()?->name, 'client' => $vip?->name ?? 'Client']],
            ['vip_checkin', 'demo:vip', ['client' => $vip?->name ?? 'VIP', 'branch' => (string) Branch::query()->orderBy('sort')->first()?->name, 'time' => '16:30']],
        ];
        foreach ($items as [$type, $fingerprint, $params]) {
            OwnerAlert::create(['type' => $type, 'fingerprint' => $fingerprint, 'data' => $params]);
            $owner?->notify(new OwnerAlertNotification($type, __('insights.alerts.'.$type.'.title', $params), __('insights.alerts.'.$type.'.body', $params),
                $type === 'big_sale' ? 'success' : 'info'));
        }
    }

    protected function signaturePng(): string
    {
        $img = imagecreatetruecolor(360, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 28, 26, 23);
        imagesetthickness($img, 3);
        $points = [[20, 90], [60, 40], [95, 85], [130, 35], [170, 80], [210, 50], [250, 78], [300, 45], [340, 60]];
        for ($i = 1; $i < count($points); $i++) {
            imageline($img, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $ink);
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
