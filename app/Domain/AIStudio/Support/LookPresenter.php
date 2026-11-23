<?php

namespace App\Domain\AIStudio\Support;

use App\Domain\AIStudio\Models\ClientLook;
use App\Domain\AIStudio\Models\Style;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Team\Models\Staff;
use Illuminate\Support\Facades\URL;

/** Everything the result screen needs: price, duration, stylist suggestion and the deep links. */
class LookPresenter
{
    /** Booking deep link contract: /book?branch={slug}&service={slug}&look={reference} */
    public static function bookUrl(?Style $style, ?ClientLook $look = null, ?Branch $branch = null): ?string
    {
        $service = $style?->service;
        if (! $service) {
            return null;
        }
        $branch ??= self::branchFor($service, $look);
        $query = array_filter(['branch' => $branch?->slug, 'service' => $service->slug, 'look' => $look?->reference]);

        return url('/book').'?'.http_build_query($query);
    }

    public static function branchFor(Service $service, ?ClientLook $look = null): ?Branch
    {
        $preferred = $look?->client?->preferred_branch_id;
        $branches = Branch::active()->orderBy('sort')->get();

        return $branches->firstWhere('id', $preferred)
            ?? $branches->first(fn (Branch $b) => $service->isActiveAt($b))
            ?? $branches->first();
    }

    public static function stylist(?Service $service): ?Staff
    {
        return $service?->staff()->where('is_active', true)->where('is_bookable', true)->orderByDesc('rating')->first();
    }

    /** "Buy this shade" — shop product page (Shop module owns the route). */
    public static function shadeUrl($variant): ?string
    {
        if (! tenant()?->hasModule('shop') || ! $variant?->product) {
            return null;
        }

        return url('/shop/p/'.$variant->product->slug).'?variant='.$variant->id;
    }

    public static function mediaUrl(ClientLook $look, string $which, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('studio.media', now()->addMinutes($minutes), ['look' => $look->reference, 'which' => $which]);
    }

    public static function shareUrl(ClientLook $look, int $days = 7): string
    {
        return URL::temporarySignedRoute('studio.share', now()->addDays($days), ['look' => $look->reference]);
    }
}
