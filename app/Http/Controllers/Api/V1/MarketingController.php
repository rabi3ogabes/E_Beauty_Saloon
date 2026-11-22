<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Models\ClientDevice;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\PurchasePackage;
use App\Domain\Marketing\Models\CampaignMessage;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Package;
use App\Domain\Marketing\Models\PackageItem;
use App\Domain\Marketing\Models\Referral;
use App\Domain\Marketing\Services\MessageRenderer;
use App\Domain\Marketing\Support\LoyaltySettings;
use App\Domain\Marketing\Support\MarketingUrl;
use App\Http\Controllers\Controller;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @group Packages, notifications & referrals
 *
 * Session packages / bundles (Marketing module), push device registration, the client's campaign inbox and
 * referral share info.
 */
class MarketingController extends Controller
{
    /**
     * Packages for sale online.
     *
     * @unauthenticated
     */
    public function packages(): JsonResponse
    {
        $packages = Package::online()->with('items.itemable')->orderBy('sort')->get();

        return response()->json(['data' => $packages->map(fn (Package $p) => $this->packagePayload($p))->values()]);
    }

    /** My packages (active first) with remaining sessions. */
    public function myPackages(Request $request): JsonResponse
    {
        $rows = ClientPackage::query()->where('client_id', $this->client($request)->id)->whereIn('status', ['active', 'used_up', 'expired', 'pending'])
            ->with(['package.items.itemable', 'usages'])->orderByRaw("case when status = 'active' then 0 else 1 end")->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (ClientPackage $cp) => [
            'id' => $cp->id,
            'status' => $cp->status,
            'usable' => $cp->isUsable(),
            'sessions_total' => (int) $cp->sessions_total,
            'sessions_remaining' => (int) $cp->sessions_remaining,
            'price_paid' => Money::of((int) $cp->price_paid, $cp->currency),
            'activated_at' => $cp->activated_at?->toIso8601ZuluString(),
            'expires_at' => $cp->expires_at?->toIso8601ZuluString(),
            'package' => $cp->package ? $this->packagePayload($cp->package) : null,
            'usages' => $cp->usages->map(fn ($u) => ['booking_id' => $u->booking_id, 'service_id' => $u->service_id, 'sessions' => (int) $u->sessions, 'used_at' => $u->used_at?->toIso8601ZuluString()])->values(),
        ])->values()]);
    }

    /**
     * Buy a package.
     *
     * Creates a pending package and returns the hosted payment page (`checkout_url`); the package activates when
     * the payment webhook arrives. Free packages activate immediately. Send an Idempotency-Key.
     *
     * @header Idempotency-Key 1f7d2c8e-9a4b-4c1e-8f3a-6b5d4e3c2a10
     */
    public function purchase(Request $request, int $id, PurchasePackage $purchase): JsonResponse
    {
        $package = Package::query()->findOrFail($id);
        [$clientPackage, $payment] = $purchase->handle($package, $this->client($request), 'online', tenant()->url('/account/packages'));

        return response()->json(['data' => [
            'client_package_id' => $clientPackage->id,
            'status' => $clientPackage->status,
            'amount' => Money::of((int) $clientPackage->price_paid, $clientPackage->currency),
            'checkout_url' => $payment?->checkout_url,
            'message' => $payment ? __('api.packages.checkout_required') : null,
        ]], 201);
    }

    /**
     * Register a push device.
     *
     * Call after sign-in and whenever FCM rotates the token. Marketing push campaigns go to the most recently seen
     * device of the client.
     *
     * @bodyParam token string required FCM registration token.
     * @bodyParam platform string required ios|android|web. Example: ios
     * @bodyParam app string client (default). Example: client
     * @bodyParam app_version string Example: 1.4.0
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:'.implode(',', ClientDevice::PLATFORMS)],
            'app' => ['nullable', 'in:client'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'locale' => ['nullable', 'in:en,ar'],
        ]);
        $client = $this->client($request);

        // A token belongs to one installation: re-registering moves it to the signed-in client.
        $device = ClientDevice::query()->updateOrCreate(['token' => $data['token']], [
            'client_id' => $client->id,
            'platform' => $data['platform'],
            'app' => $data['app'] ?? 'client',
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform, 'registered_at' => $device->updated_at->toIso8601ZuluString()], 'message' => __('api.devices.registered')], 201);
    }

    /** Unregister a push device (sign-out). */
    public function deleteDevice(Request $request, string $token)
    {
        ClientDevice::query()->where('client_id', $this->client($request)->id)->where('token', $token)->delete();

        return response()->noContent();
    }

    /**
     * Notification inbox.
     *
     * Campaign messages that reached the client (any channel), newest first, rendered in the client's language.
     */
    public function notifications(Request $request, MessageRenderer $renderer): JsonResponse
    {
        $client = $this->client($request);
        $locale = app()->getLocale();
        $page = CampaignMessage::query()->where('client_id', $client->id)->whereIn('status', CampaignMessage::SENT_STATUSES)
            ->with(['campaign.banner', 'variant'])->latest('sent_at')->latest('id')->paginate(min(50, $request->integer('per_page', 20)));

        $data = $page->getCollection()->filter(fn (CampaignMessage $m) => $m->campaign)->map(function (CampaignMessage $m) use ($client, $renderer, $locale) {
            $content = $m->variant?->content ?: $m->campaign->content;
            $link = MarketingUrl::trackedLink($m->token);
            $extra = ['link' => $link, 'unsubscribe' => MarketingUrl::unsubscribe($m->token)];
            $pick = fn (string $key) => $renderer->render($renderer->localized(collect($content)->map(fn ($c) => $c[$key] ?? null)->all(), $locale), $client, $extra);

            return [
                'id' => $m->id,
                'title' => $pick('subject') ?: $m->campaign->name,
                'body' => $pick('body'),
                'image_url' => $m->campaign->mediaUrl(),
                'link' => $link,
                'channel' => $m->channel,
                'read' => $m->read_at !== null || $m->clicked_at !== null,
                'sent_at' => ($m->sent_at ?? $m->created_at)?->toIso8601ZuluString(),
            ];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    /** Referral code, share link and rewards. */
    public function referral(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $link = MarketingUrl::to('/r/'.$client->referral_code);
        $referrals = Referral::query()->where('referrer_client_id', $client->id)->get(['status', 'points_awarded']);
        $points = (int) LoyaltySettings::referral('referrer_points');
        $discount = (int) LoyaltySettings::referral('friend_discount_bp') / 100;

        return response()->json(['data' => [
            'code' => $client->referral_code,
            'link' => $link,
            'share_text' => __('marketing.referrals.share_text', ['salon' => tenant()->name, 'discount' => $discount, 'link' => $link]),
            'rewards' => ['referrer_points' => $points, 'friend_discount_percent' => $discount, 'friend_coupon_days' => (int) LoyaltySettings::referral('friend_coupon_days')],
            'stats' => [
                'invited' => $referrals->count(),
                'rewarded' => $referrals->where('status', 'rewarded')->count(),
                'points_earned' => (int) $referrals->sum('points_awarded'),
            ],
        ]]);
    }

    // ------------------------------------------------------------------------------------------

    protected function client(Request $request): Client
    {
        return $request->user();
    }

    protected function packagePayload(Package $p): array
    {
        $currency = tenant()?->currency ?? 'QAR';

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'price' => Money::of($p->effectivePrice(), $currency),
            'value' => Money::of($p->itemsValue(), $currency),
            'savings' => Money::of($p->savings(), $currency),
            'sessions' => (int) $p->sessions_count,
            'validity_days' => $p->validity_days,
            'branch_ids' => $p->branch_ids,
            'image_url' => $p->image_path ? Storage::disk('public')->url($p->image_path) : null,
            'items' => $p->items->map(fn (PackageItem $i) => [
                'type' => $i->itemable instanceof Service ? 'service' : 'product',
                'id' => $i->itemable_id,
                'name' => $i->itemable instanceof Service ? $i->itemable->name : ($i->itemable?->product?->name ?? $i->itemable?->name),
                'quantity' => (int) $i->quantity,
            ])->values(),
        ];
    }
}
