<?php

namespace App\Http\Controllers\Site;

use App\Domain\AIStudio\Actions\ClientAIData;
use App\Domain\AIStudio\Actions\ClientQuota;
use App\Domain\AIStudio\Actions\ComposeShareImage;
use App\Domain\AIStudio\Actions\RecordConsent;
use App\Domain\AIStudio\Actions\RequestConsultation;
use App\Domain\AIStudio\Actions\RequestTryOn;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\AILookPurchase;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\LookPresenter;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Public AI Beauty Studio (/studio) and in-salon kiosk (/studio/kiosk). */
class StudioController extends Controller
{
    public function index(Request $request)
    {
        return $this->page($request, kiosk: false);
    }

    public function kiosk(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->setting('ai.kiosk_enabled', true), 404);
        $key = (string) $tenant->setting('ai.kiosk_key', '');
        if ($key !== '') {
            if (hash_equals($key, (string) $request->query('key'))) {
                $request->session()->put('ai_kiosk', true);
            }
            abort_unless($request->session()->get('ai_kiosk'), 403);
        } else {
            $request->session()->put('ai_kiosk', true);
        }
        // Next guest at the tablet: a new session id = a new anonymous device (consent, quota, looks).
        if ($request->boolean('reset')) {
            $request->session()->forget('ai_kiosk_guest');
            $request->session()->regenerate();

            return redirect()->route('studio.kiosk');
        }

        return $this->page($request, kiosk: true);
    }

    protected function page(Request $request, bool $kiosk)
    {
        $tenant = tenant();
        $client = $request->user('client');
        $device = $this->device($request);
        $styles = Style::active()->with('service')->get();
        $subject = ClientQuota::subject($client, $device);

        return response()->view('site.ai-studio.index', [
            'tenant' => $tenant,
            'kiosk' => $kiosk,
            'client' => $client,
            'consent' => RecordConsent::current($client, $device),
            'categories' => collect(Style::CATEGORIES)->keys()->filter(fn ($c) => $styles->contains('category', $c))->values(),
            'styles' => $styles->map(fn (Style $s) => [
                'id' => $s->id,
                'category' => $s->category,
                'option' => $s->option,
                'name' => $s->name,
                'description' => $s->description,
                'swatch' => $s->swatch,
                'image' => $s->reference_path ? Storage::disk('public')->url($s->reference_path) : null,
                'framing' => $s->framing(),
                'face' => $s->isFaceFeature(),
                'price' => $s->service ? money($s->service->sale_price ?? $s->service->price) : null,
                'duration' => $s->service?->duration_minutes,
            ])->values(),
            'quota' => app(ClientQuota::class)->status($tenant, $subject, $client),
            'pricePerLook' => (int) $tenant->setting('ai.price_per_look', 0),
        ]);
    }

    public function consent(Request $request, RecordConsent $record)
    {
        $data = $request->validate(['accept' => ['accepted'], 'adult' => ['required', 'in:yes,no']]);
        $record->handle($request->user('client'), $this->device($request), $data['adult'] === 'yes', $request->ip(), $request->userAgent());

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'minor' => $data['adult'] === 'no'])
            : back()->with('status', __('ai_studio.consent.saved'));
    }

    public function withdrawConsent(Request $request)
    {
        RecordConsent::withdraw($request->user('client'), $this->device($request));

        return back()->with('status', __('ai_studio.consent.withdrawn'));
    }

    public function tryOn(Request $request, RequestTryOn $tryOn)
    {
        $data = $request->validate([
            'style_id' => ['required', 'integer'],
            'photo' => ['required', 'file', 'mimes:'.implode(',', config('ai.mimes')), 'max:'.config('ai.max_upload_kb')],
        ]);
        $style = Style::findOrFail($data['style_id']);

        try {
            $job = $tryOn->handle(tenant(), $style, (string) file_get_contents($data['photo']->getRealPath()), $request->user('client'),
                $this->device($request), $request->session()->get('ai_kiosk') ? 'kiosk' : 'studio', app()->getLocale());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->jobPayload($job), 202);
    }

    public function consultant(Request $request, RequestConsultation $consult)
    {
        $data = $request->validate(['photo' => ['required', 'file', 'mimes:'.implode(',', config('ai.mimes')), 'max:'.config('ai.max_upload_kb')]]);
        try {
            $job = $consult->handle(tenant(), (string) file_get_contents($data['photo']->getRealPath()), $request->user('client'), $this->device($request), app()->getLocale());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->jobPayload($job), 202);
    }

    /** Polling endpoint for the studio UI. */
    public function status(Request $request, AIJob $job)
    {
        $this->authorizeOwner($request, $job->client_id, $job->device_fingerprint);

        return response()->json($this->jobPayload($job));
    }

    public function look(Request $request, ClientLook $look)
    {
        $this->authorizeOwner($request, $look->client_id, $look->device_fingerprint);
        $look->load('style.service', 'job');
        $service = $look->style?->service;
        $branch = $service ? LookPresenter::branchFor($service, $look) : null;

        return view('site.ai-studio.look', [
            'tenant' => tenant(),
            'look' => $look,
            'style' => $look->style,
            'service' => $service,
            'branch' => $branch,
            'price' => $service ? $service->priceAt($branch) : null,
            'duration' => $service ? $service->durationAt($branch) : null,
            'stylist' => LookPresenter::stylist($service),
            'bookUrl' => tenant()->hasModule('booking') ? LookPresenter::bookUrl($look->style, $look, $branch) : null,
            'shades' => $look->style?->shades() ?? collect(),
            'before' => $look->before_path ? LookPresenter::mediaUrl($look, 'before') : null,
            'after' => $look->after_path ? LookPresenter::mediaUrl($look, 'after') : null,
            'shareUrl' => $look->after_path ? LookPresenter::shareUrl($look) : null,
            'kiosk' => (bool) $request->session()->get('ai_kiosk'),
        ]);
    }

    public function save(Request $request, ClientLook $look)
    {
        $client = $request->user('client');
        abort_unless($client && ($look->client_id === $client->id || ($look->client_id === null && $look->device_fingerprint === $this->device($request))), 403);
        $look->update(['client_id' => $client->id, 'saved' => true, 'expires_at' => null]);
        AIJob::whereKey($look->ai_job_id)->whereNull('client_id')->update(['client_id' => $client->id]);

        return back()->with('status', __('ai_studio.look.saved'));
    }

    public function media(Request $request, ClientLook $look, string $which, PhotoVault $vault)
    {
        abort_unless(in_array($which, ['before', 'after'], true), 404);
        $bin = $vault->get($which === 'before' ? $look->before_path : $look->after_path);
        abort_unless($bin, 404);

        return response($bin, 200, [
            'Content-Type' => PhotoVault::mime($bin),
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function share(Request $request, ClientLook $look, ComposeShareImage $compose)
    {
        try {
            $jpeg = $compose->handle($look, tenant());
        } catch (DomainException) {
            abort(404);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="'.Str::slug(tenant()->name).'-'.$look->reference.'.jpg"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function consultation(Request $request, AIJob $job)
    {
        abort_unless($job->task === 'consultant', 404);
        $this->authorizeOwner($request, $job->client_id, $job->device_fingerprint);
        $looks = collect(data_get($job->output_refs, 'looks', []));
        $styles = Style::with('service')->whereIn('id', $looks->pluck('style_id'))->get()->keyBy('id');

        return view('site.ai-studio.consultation', [
            'tenant' => tenant(),
            'job' => $job,
            'analysis' => data_get($job->output_refs, 'analysis', []),
            'recommendations' => $looks->map(fn ($l) => ['style' => $styles->get($l['style_id']), 'reason' => $l['reason']])->filter(fn ($r) => $r['style'])->values(),
        ]);
    }

    /** Client buys extra looks through the salon's own payment gateway. */
    public function purchase(Request $request, CreateOnlinePayment $payments)
    {
        $tenant = tenant();
        $price = (int) $tenant->setting('ai.price_per_look', 0);
        $data = $request->validate(['looks' => ['required', 'integer', 'min:1', 'max:20']]);
        abort_if($price <= 0, 404);

        $gateway = TenantPaymentGateway::for('ai_studio')->first() ?? TenantPaymentGateway::for('booking')->first(fn ($g) => $g->isOnline());
        if (! $gateway || ! $gateway->isOnline()) {
            return back()->withErrors(['domain' => __('ai_studio.errors.no_gateway')]);
        }
        $client = $request->user('client');
        $purchase = AILookPurchase::create([
            'client_id' => $client?->id,
            'subject' => ClientQuota::subject($client, $this->device($request)),
            'looks' => $data['looks'],
            'amount' => $price * $data['looks'],
        ]);
        $payment = $payments->handle($purchase, $gateway, $purchase->amount, route('studio.index'),
            array_filter(['name' => $client?->name, 'phone' => $client?->phone, 'email' => $client?->email]), 'ai_looks', null, $client?->id);

        return redirect()->away($payment->checkout_url);
    }

    public function exportData(Request $request, ClientAIData $data)
    {
        return response()->json($data->export($request->user('client')), 200, [
            'Content-Disposition' => 'attachment; filename="my-ai-studio-data.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function eraseData(Request $request, ClientAIData $data)
    {
        $data->erase($request->user('client'));

        return back()->with('status', __('ai_studio.privacy.erased'));
    }

    protected function jobPayload(AIJob $job): array
    {
        $job->loadMissing('look');

        return [
            'id' => $job->id,
            'status' => $job->status,
            'error' => in_array($job->status, ['failed', 'refunded'], true) ? __('ai_studio.errors.try_again') : null,
            'status_url' => route('studio.status', $job),
            'redirect' => $job->status === 'succeeded'
                ? ($job->task === 'consultant' ? route('studio.consultation', $job) : ($job->look ? route('studio.look', $job->look) : null))
                : null,
        ];
    }

    protected function authorizeOwner(Request $request, ?int $clientId, ?string $device): void
    {
        $client = $request->user('client');
        $ok = ($clientId && $client && $client->id === $clientId) || ($device && hash_equals($device, $this->device($request)));
        abort_unless($ok, 403);
    }

    /** Anonymous device fingerprint (cookie, hashed). In kiosk mode each browser session counts as one device. */
    protected function device(Request $request): string
    {
        if ($request->hasSession() && $request->session()->get('ai_kiosk')) {
            $guest = $request->session()->get('ai_kiosk_guest') ?? tap((string) Str::uuid(), fn ($id) => $request->session()->put('ai_kiosk_guest', $id));

            return substr(hash('sha256', 'kiosk|'.$guest), 0, 40);
        }
        $raw = (string) ($request->cookie('luxe_device') ?: $request->attributes->get('luxe_device') ?: '');
        if ($raw === '') {
            $raw = (string) Str::uuid();
            $request->attributes->set('luxe_device', $raw);
            Cookie::queue('luxe_device', $raw, 60 * 24 * 365);
        }

        return substr(hash('sha256', 'device|'.$raw), 0, 40);
    }
}
