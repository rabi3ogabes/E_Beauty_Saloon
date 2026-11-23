<?php

namespace App\Domain\Websites\Support;

use App\Domain\Billing\Models\Plan;
use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Models\BlogPost;

/** Sanitizers for values injected into CSS/JS + schema.org builders. */
class SiteSeo
{
    /** Only #rgb / #rrggbb / #rrggbbaa colors reach the stylesheet. */
    public static function cssColor(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', trim($value)) ? trim($value) : $fallback;
    }

    /** Font family names: letters, digits, spaces and dashes only. */
    public static function cssFont(?string $value): string
    {
        return preg_replace('/[^A-Za-z0-9 \-]/', '', (string) $value) ?: 'Inter';
    }

    /** Validated analytics IDs from tenant settings (never raw HTML). */
    public static function analytics(Tenant $tenant): array
    {
        $ga = (string) $tenant->setting('website.ga4_id', '');
        $meta = (string) $tenant->setting('website.meta_pixel_id', '');
        $tiktok = (string) $tenant->setting('website.tiktok_pixel_id', '');

        return [
            'ga4' => preg_match('/^G-[A-Z0-9]{4,16}$/', $ga) ? $ga : null,
            'meta' => preg_match('/^\d{6,20}$/', $meta) ? $meta : null,
            'tiktok' => preg_match('/^[A-Z0-9]{8,30}$/', $tiktok) ? $tiktok : null,
        ];
    }

    public static function organization(Tenant $tenant): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BeautySalon',
            'name' => $tenant->name,
            'url' => $tenant->url(),
            'logo' => $tenant->brand('logo_path') ? asset('storage/'.$tenant->brand('logo_path')) : null,
            'image' => $tenant->brand('hero_path') ? asset('storage/'.$tenant->brand('hero_path')) : null,
            'telephone' => $tenant->phone,
            'email' => $tenant->email,
            'sameAs' => array_values(array_filter((array) $tenant->brand('social'))),
        ]);
    }

    /** LocalBusiness / BeautySalon JSON-LD for a branch landing page. */
    public static function branch(Tenant $tenant, Branch $branch, iterable $services = []): array
    {
        $days = ['sun' => 'Sunday', 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday'];
        $hours = [];
        foreach (($branch->opening_hours ?? Branch::defaultHours()) as $d => $h) {
            if (! ($h['closed'] ?? false) && isset($days[$d])) {
                $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.$days[$d], 'opens' => $h['open'], 'closes' => $h['close']];
            }
        }
        $offers = [];
        foreach ($services as $service) {
            $offers[] = ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $service->getTranslation('name', 'en')],
                'price' => number_format($service->priceAt($branch) / 100, 2, '.', ''), 'priceCurrency' => $tenant->currency];
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => ['BeautySalon', 'LocalBusiness'],
            '@id' => $tenant->url('branches/'.$branch->slug).'#salon',
            'name' => $tenant->name.' — '.$branch->getTranslation('name', 'en'),
            'url' => $tenant->url('branches/'.$branch->slug),
            'telephone' => $branch->phone,
            'email' => $branch->email,
            'image' => $branch->cover_path ? asset('storage/'.$branch->cover_path) : ($tenant->brand('hero_path') ? asset('storage/'.$tenant->brand('hero_path')) : null),
            'priceRange' => '$$$',
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $branch->getTranslation('address', 'en', false) ?: null,
                'addressLocality' => $branch->city,
                'addressRegion' => $branch->area,
                'addressCountry' => $tenant->country,
            ]),
            'geo' => $branch->lat && $branch->lng ? ['@type' => 'GeoCoordinates', 'latitude' => $branch->lat, 'longitude' => $branch->lng] : null,
            'openingHoursSpecification' => $hours,
            'makesOffer' => $offers ?: null,
            'parentOrganization' => ['@type' => 'Organization', 'name' => $tenant->name, 'url' => $tenant->url()],
            'potentialAction' => $tenant->hasModule('booking') ? ['@type' => 'ReserveAction', 'target' => $tenant->url('book?branch='.$branch->slug)] : null,
        ]);
    }

    /** @param iterable<array{q:string, a:string}> $items */
    public static function faq(iterable $items): array
    {
        $entities = [];
        foreach ($items as $i) {
            $entities[] = ['@type' => 'Question', 'name' => $i['q'] ?? '', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a'] ?? '']];
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /** @param array<string, string> $crumbs name => url */
    public static function breadcrumbs(array $crumbs): array
    {
        $items = [];
        $pos = 1;
        foreach ($crumbs as $name => $url) {
            $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $name, 'item' => $url];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    public static function blogPosting(BlogPost $post): array
    {
        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post->title, 'description' => $post->excerpt,
            'datePublished' => $post->published_at?->toAtomString(), 'dateModified' => $post->updated_at?->toAtomString(),
            'author' => ['@type' => 'Person', 'name' => $post->author_name ?: config('salon.name')],
            'publisher' => ['@type' => 'Organization', 'name' => config('salon.name')],
            'image' => $post->cover_path ? asset('storage/'.$post->cover_path) : null, 'mainEntityOfPage' => url('/blog/'.$post->slug),
        ]);
    }

    /** Organization + SoftwareApplication graph for the platform website. */
    public static function platform(): array
    {
        $brand = config('salon.name');
        $from = Plan::public()->min('monthly_price') ?? 0;

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => url('/').'#org', 'name' => $brand, 'url' => url('/'), 'logo' => url('/favicon.ico')],
                ['@type' => 'SoftwareApplication', 'name' => $brand, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
                    'description' => __('platform.meta_description'), 'publisher' => ['@id' => url('/').'#org'],
                    'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => config('salon.defaults.currency'), 'lowPrice' => number_format($from / 100, 2, '.', '')]],
            ],
        ];
    }

    public static function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>';
    }
}
