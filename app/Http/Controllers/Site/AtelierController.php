<?php

namespace App\Http\Controllers\Site;

use App\Domain\AIStudio\Actions\RecordConsent;
use App\Domain\AIStudio\Actions\RequestAtelierAdvice;
use App\Domain\AIStudio\Actions\RequestAtelierRender;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\AtelierDesign;
use App\Domain\AIStudio\Support\AIRouter;
use App\Domain\AIStudio\Support\AtelierCatalog;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Live Atelier (/studio/atelier): lashes, brows, lips and nails previewed live on the client's
 * device (camera, photo or a sample model), then "Make it real" with the image AI, advice from the
 * AI artist, saved designs and "Book this look". The live preview never uploads anything.
 */
class AtelierController extends StudioController
{
    public const MEDIAPIPE_VERSION = '1.2.3';

    public function show(Request $request)
    {
        $tenant = tenant();
        $client = $request->user('client');
        $device = $this->device($request);
        $router = app(AIRouter::class);

        return response()->view('site.ai-studio.atelier', [
            'tenant' => $tenant,
            'client' => $client,
            'consent' => RecordConsent::current($client, $device),
            'config' => [
                'catalog' => AtelierCatalog::forClient(),
                'categories' => collect(AtelierCatalog::CATEGORIES)->map(fn ($c, $key) => ['key' => $key, 'framing' => $c['framing'], 'face' => $c['face']])->values(),
                'assets' => self::assets(),
                'samples' => self::samples(),
                'renderAvailable' => $router->candidates('eyes_tryon', 'image', $tenant->id)->isNotEmpty(),
                'adviceAvailable' => $router->candidates('atelier_advice', 'text', $tenant->id)->isNotEmpty(),
                'renderCredits' => (int) AITaskPrice::for('eyes_tryon')->credits,
                'booking' => $tenant->hasModule('booking'),
                'designs' => $this->designs($request),
                'urls' => [
                    'consent' => route('studio.consent'),
                    'render' => route('studio.atelier.render'),
                    'advice' => route('studio.atelier.advice'),
                    'designs' => route('studio.atelier.designs.store'),
                    'studio' => route('studio.index'),
                ],
            ],
        ]);
    }

    public function render(Request $request, RequestAtelierRender $render)
    {
        $data = $this->validated($request, withGuide: true);
        try {
            $job = $render->handle(tenant(), $data['category'], (array) json_decode($data['params'], true), (string) file_get_contents($data['photo']->getRealPath()),
                isset($data['guide']) ? (string) file_get_contents($data['guide']->getRealPath()) : null,
                $request->user('client'), $this->device($request), $request->session()->get('ai_kiosk') ? 'kiosk' : 'atelier', app()->getLocale());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->jobPayload($job), 202);
    }

    public function advice(Request $request, RequestAtelierAdvice $advice)
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(AtelierCatalog::CATEGORIES))],
            'photo' => ['required', 'file', 'mimes:'.implode(',', config('ai.mimes')), 'max:'.config('ai.max_upload_kb')],
        ]);
        try {
            $job = $advice->handle(tenant(), $data['category'], (string) file_get_contents($data['photo']->getRealPath()), $request->user('client'), $this->device($request), app()->getLocale());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->jobPayload($job), 202);
    }

    /** Save a design (settings only). Returns it with its booking link. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(AtelierCatalog::CATEGORIES))],
            'params' => ['required', 'array'],
        ]);
        $client = $request->user('client');
        $device = $this->device($request);
        $params = AtelierCatalog::normalize($data['category'], $data['params']);

        $owned = AtelierDesign::query()->ownedBy($client, $device);
        $design = (clone $owned)->where('category', $data['category'])->get()->first(fn (AtelierDesign $d) => $d->params == $params);
        if (! $design) {
            if ((clone $owned)->count() >= AtelierDesign::MAX_PER_OWNER) {
                (clone $owned)->oldest()->first()?->delete();
            }
            $design = AtelierDesign::create([
                'client_id' => $client?->id,
                'device_fingerprint' => $device,
                'category' => $data['category'],
                'params' => $params,
                'summary' => AtelierCatalog::summary($data['category'], $params, 'en'),
                'service_id' => AtelierCatalog::serviceFor($data['category'])?->id,
            ]);
        }

        return response()->json(['design' => $this->present($design), 'designs' => $this->designs($request)], 201);
    }

    public function destroy(Request $request, AtelierDesign $design)
    {
        abort_unless(AtelierDesign::query()->ownedBy($request->user('client'), $this->device($request))->whereKey($design->id)->exists(), 403);
        $design->delete();

        return response()->json(['designs' => $this->designs($request)]);
    }

    protected function designs(Request $request): array
    {
        return AtelierDesign::query()->ownedBy($request->user('client'), $this->device($request))->with('service')->latest()->limit(AtelierDesign::MAX_PER_OWNER)->get()
            ->map(fn (AtelierDesign $d) => $this->present($d))->all();
    }

    protected function present(AtelierDesign $d): array
    {
        $service = $d->service;

        return [
            'reference' => $d->reference,
            'category' => $d->category,
            'params' => $d->params,
            'summary' => $d->localizedSummary(),
            'book' => tenant()->hasModule('booking') ? url('/book').'?'.http_build_query(array_filter(['service' => $service?->slug, 'look' => $d->reference])) : null,
            'price' => $service ? money($service->sale_price ?? $service->price) : null,
            'delete' => route('studio.atelier.designs.destroy', $d),
        ];
    }

    protected function validated(Request $request, bool $withGuide = false): array
    {
        return $request->validate(array_filter([
            'category' => ['required', Rule::in(array_keys(AtelierCatalog::CATEGORIES))],
            'params' => ['required', 'string', 'max:2000', 'json'],
            'photo' => ['required', 'file', 'mimes:'.implode(',', config('ai.mimes')), 'max:'.config('ai.max_upload_kb')],
            'guide' => $withGuide ? ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('ai.max_upload_kb')] : null,
        ]));
    }

    /**
     * On-device tracking runtime + models: self-hosted when `studio:install-atelier` has run
     * (storage/app/public/vendor/mediapipe/{version}), otherwise Google's and jsDelivr's CDNs.
     */
    public static function assets(): array
    {
        $dir = 'vendor/mediapipe/'.self::MEDIAPIPE_VERSION;
        $disk = Storage::disk('public');
        if ($disk->exists($dir.'/face_landmarker.task') && $disk->exists($dir.'/hand_landmarker.task') && $disk->exists($dir.'/wasm/vision_wasm_internal.wasm')) {
            // Same-origin path: salons run on their own domains, and the runtime is fetched with CORS rules.
            $base = '/storage/'.$dir;

            return ['wasm' => $base.'/wasm', 'face' => $base.'/face_landmarker.task', 'hand' => $base.'/hand_landmarker.task'];
        }

        return [
            'wasm' => 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@'.self::MEDIAPIPE_VERSION.'/wasm',
            'face' => 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task',
            'hand' => 'https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task',
        ];
    }

    /** Sample models to try designs on without a photo (bundled in public/images/atelier). */
    public static function samples(): array
    {
        return collect([
            ['key' => 'face-1', 'framing' => 'face'],
            ['key' => 'face-2', 'framing' => 'face'],
            ['key' => 'hand-1', 'framing' => 'hand'],
            ['key' => 'hand-2', 'framing' => 'hand'],
        ])->filter(fn ($s) => is_file(public_path('images/atelier/'.$s['key'].'.jpg')))
            ->map(fn ($s) => $s + ['src' => asset('images/atelier/'.$s['key'].'.jpg'), 'thumb' => asset('images/atelier/'.$s['key'].'-thumb.jpg')])
            ->values()->all();
    }
}
