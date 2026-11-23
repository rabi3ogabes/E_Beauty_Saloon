<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AIStudio\Actions\ClientQuota;
use App\Domain\AIStudio\Actions\RecordConsent;
use App\Domain\AIStudio\Actions\RequestTryOn;
use App\Domain\AIStudio\Models\AIJob;
use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\LookPresenter;
use App\Domain\AIStudio\Support\PhotoVault;
use App\Domain\CRM\Models\Client;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * @group AI Studio
 *
 * Virtual try-on for the client app. Same safety gates as the website (consent, minors, moderation, client quota,
 * salon credit wallet) because every job goes through RequestTryOn. Photos are encrypted at rest and only served
 * through short-lived signed URLs on the salon's own domain.
 */
class StudioController extends Controller
{
    /**
     * Style library.
     *
     * Signed-in clients also get their remaining tries and consent state in `meta`.
     *
     * @unauthenticated
     *
     * @queryParam category string hair|eyes|lips|nails|pedicure|makeup|bridal. Example: hair
     */
    public function styles(Request $request, ClientQuota $quota): JsonResponse
    {
        $request->validate(['category' => ['nullable', 'string', 'max:20']]);
        $styles = Style::active()->with('service')->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))->get();
        $client = $this->optionalClient($request);
        $tenant = tenant();

        return response()->json([
            'data' => $styles->map(fn (Style $s) => $this->stylePayload($s))->values(),
            'meta' => [
                'categories' => array_keys(Style::CATEGORIES),
                'consent_version' => config('ai.consent_version'),
                'consent_given' => $client ? (bool) RecordConsent::current($client, null) : null,
                'quota' => $client ? $quota->status($tenant, ClientQuota::subject($client, null), $client) : null,
                'price_per_look' => ($p = (int) $tenant->setting('ai.price_per_look', 0)) > 0 ? Money::of($p, $tenant->currency ?? 'QAR') : null,
            ],
        ]);
    }

    /**
     * Give AI photo consent.
     *
     * Required once (per consent version) before the first try-on; `adult=false` blocks face styles.
     *
     * @bodyParam accept boolean required Must be true. Example: true
     * @bodyParam adult boolean required The client confirms they are 18 or older. Example: true
     */
    public function consent(Request $request, RecordConsent $record): JsonResponse
    {
        $data = $request->validate(['accept' => ['accepted'], 'adult' => ['required', 'boolean']]);
        $consent = $record->handle($this->client($request), null, (bool) $data['adult'], $request->ip(), $request->userAgent());

        return response()->json(['data' => ['version' => $consent->version, 'minor' => (bool) $consent->is_minor, 'accepted_at' => $consent->accepted_at->toIso8601ZuluString()]], 201);
    }

    /** Withdraw AI photo consent. */
    public function withdrawConsent(Request $request)
    {
        RecordConsent::withdraw($this->client($request), null);

        return response()->noContent();
    }

    /**
     * Start a try-on.
     *
     * Multipart upload. Poll GET /studio/jobs/{id} until `status` is succeeded or failed. Credits are charged
     * only when the look succeeds. `consent`/`adult` may be sent here instead of calling POST /studio/consent.
     *
     * @bodyParam photo file required JPEG/PNG/WebP selfie.
     * @bodyParam style_id integer required Example: 4
     * @bodyParam category string Must match the style's category. Example: hair
     * @bodyParam consent boolean Records consent first when true. Example: true
     * @bodyParam adult boolean Age confirmation for the inline consent. Example: true
     */
    public function createJob(Request $request, RequestTryOn $tryOn, RecordConsent $record): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:'.implode(',', config('ai.mimes')), 'max:'.config('ai.max_upload_kb')],
            'style_id' => ['required', 'integer'],
            'category' => ['nullable', 'string', 'max:20'],
            'consent' => ['nullable', 'boolean'],
            'adult' => ['nullable', 'boolean', 'required_if:consent,1,true'],
        ]);
        $client = $this->client($request);
        $style = Style::findOrFail($data['style_id']);
        if (! empty($data['category']) && $data['category'] !== $style->category) {
            throw new DomainException(__('api.studio.style_category_mismatch'));
        }
        if (! empty($data['consent']) && ! RecordConsent::current($client, null)) {
            $record->handle($client, null, (bool) $data['adult'], $request->ip(), $request->userAgent());
        }

        $job = $tryOn->handle(tenant(), $style, (string) file_get_contents($data['photo']->getRealPath()), $client, null, 'app', app()->getLocale());

        return response()->json(['data' => $this->jobPayload($job->fresh(['look.style.service', 'style.service']))], 202);
    }

    /** Try-on status and result. */
    public function job(Request $request, int $id): JsonResponse
    {
        $job = AIJob::query()->where('client_id', $this->client($request)->id)->with(['look.style.service', 'style.service'])->findOrFail($id);

        return response()->json(['data' => $this->jobPayload($job)]);
    }

    /**
     * My looks.
     *
     * @queryParam saved boolean Only saved looks. Example: true
     */
    public function looks(Request $request)
    {
        $page = ClientLook::query()->where('client_id', $this->client($request)->id)->whereNotNull('after_path')
            ->where(fn ($q) => $q->where('saved', true)->orWhereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($request->boolean('saved'), fn ($q) => $q->where('saved', true))
            ->with('style.service')->latest('id')->paginate(min(50, $request->integer('per_page', 20)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ClientLook $l) => $this->lookPayload($l))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** Save a look (kept until deleted instead of expiring with the retention window). */
    public function saveLook(Request $request, string $reference): JsonResponse
    {
        $look = $this->ownLook($request, $reference);
        if (! $look->after_path) {
            throw new DomainException(__('api.studio.look_not_saved'));
        }
        $look->update(['saved' => true, 'expires_at' => null]);

        return response()->json(['data' => $this->lookPayload($look->fresh('style.service'))]);
    }

    /** Delete a look and its photos. */
    public function deleteLook(Request $request, string $reference, PhotoVault $vault)
    {
        $look = $this->ownLook($request, $reference);
        DB::transaction(function () use ($look, $vault) {
            $vault->delete($look->before_path, $look->after_path);
            $look->delete();
        });
        activity()->performedOn($look)->withProperties(['reference' => $look->reference])->log('ai.look.deleted');

        return response()->noContent();
    }

    /**
     * Look photo (signed URL from the job / look payload).
     *
     * @unauthenticated
     */
    public function media(Request $request, string $reference, string $which, PhotoVault $vault)
    {
        abort_unless(in_array($which, ['before', 'after'], true), 404);
        $look = ClientLook::query()->where('reference', $reference)->firstOrFail();
        $bin = $vault->get($which === 'before' ? $look->before_path : $look->after_path);
        abort_unless($bin, 404);

        return response($bin, 200, ['Content-Type' => PhotoVault::mime($bin), 'Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }

    // ------------------------------------------------------------------------------------------

    protected function client(Request $request): Client
    {
        return $request->user();
    }

    protected function optionalClient(Request $request): ?Client
    {
        $user = $request->user('sanctum');

        return $user instanceof Client && (int) $user->tenant_id === (int) tenant()->id && $user->tokenCan('client') ? $user : null;
    }

    protected function ownLook(Request $request, string $reference): ClientLook
    {
        return ClientLook::query()->where('client_id', $this->client($request)->id)->where('reference', $reference)->firstOrFail();
    }

    protected function stylePayload(Style $s): array
    {
        $currency = tenant()?->currency ?? 'QAR';
        $service = $s->service;

        return [
            'id' => $s->id,
            'category' => $s->category,
            'option' => $s->option,
            'name' => $s->name,
            'description' => $s->description,
            'thumbnail_url' => $s->reference_path ? Storage::disk('public')->url($s->reference_path) : null,
            'swatch' => $s->swatch,
            'framing' => $s->framing(),
            'face' => $s->isFaceFeature(),
            'service_id' => $s->service_id,
            'price' => $service ? Money::of((int) ($service->sale_price ?? $service->price), $currency) : null,
            'duration_minutes' => $service?->duration_minutes,
        ];
    }

    protected function jobPayload(AIJob $job): array
    {
        $look = $job->look;
        $finished = $job->isFinished();

        return array_merge([
            'id' => $job->id,
            'status' => $job->status,
            'progress' => match ($job->status) {
                'queued' => 0.1, 'running' => 0.5, default => 1.0,
            },
            'finished' => $finished,
            'error' => in_array($job->status, ['failed', 'refunded'], true) ? __('ai_studio.errors.try_again') : null,
            'style_id' => $job->style_id,
            'style_name' => $job->style?->name,
            'created_at' => $job->created_at?->toIso8601ZuluString(),
        ], $look && $job->status === 'succeeded' ? $this->lookPayload($look, includeId: false) : [
            'look_id' => $look?->reference,
            'before_url' => $look?->before_path ? $this->mediaUrl($look, 'before') : null,
            'after_url' => null,
        ]);
    }

    protected function lookPayload(ClientLook $look, bool $includeId = true): array
    {
        $style = $look->style;
        $service = $style?->service;
        $branch = $service ? LookPresenter::branchFor($service, $look) : null;
        $currency = tenant()?->currency ?? 'QAR';
        $canBook = $service && tenant()->hasModule('booking');

        return array_merge($includeId ? ['id' => $look->reference] : [], [
            'look_id' => $look->reference,
            'category' => $look->category,
            'style_id' => $look->style_id,
            'style_name' => $style?->name,
            'before_url' => $look->before_path ? $this->mediaUrl($look, 'before') : null,
            'after_url' => $look->after_path ? $this->mediaUrl($look, 'after') : null,
            'saved' => (bool) $look->saved,
            'expires_at' => $look->expires_at?->toIso8601ZuluString(),
            'service_id' => $service?->id,
            'price' => $service ? Money::of($service->priceAt($branch), $currency) : null,
            'duration_minutes' => $service ? $service->durationAt($branch) : null,
            'book' => $canBook ? [
                'branch_id' => $branch?->id,
                'service_id' => $service->id,
                'look_reference' => $look->reference,
                'url' => tenant()->url('/book?'.http_build_query(array_filter(['branch' => $branch?->slug, 'service' => $service->slug, 'look' => $look->reference]))),
            ] : null,
            'created_at' => $look->created_at?->toIso8601ZuluString(),
        ]);
    }

    /** Relative signature, served on the salon's own host (image loaders cannot send X-Tenant). */
    protected function mediaUrl(ClientLook $look, string $which): string
    {
        return tenant()->url(URL::temporarySignedRoute('api.v1.studio.media', now()->addMinutes(60), ['reference' => $look->reference, 'which' => $which], absolute: false));
    }
}
