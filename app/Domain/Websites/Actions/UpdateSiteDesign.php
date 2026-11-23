<?php

namespace App\Domain\Websites\Actions;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Websites\Support\SiteSeo;
use App\Domain\Websites\Support\ThemeRegistry;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Cache;

/**
 * Owner changes theme / colours / fonts / imagery. Stored in tenant branding tokens which the layout
 * injects as CSS variables + a theme class — the live site changes on the next request, no deploy.
 */
class UpdateSiteDesign
{
    public const FONTS = ['Playfair Display', 'Cormorant Garamond', 'Bodoni Moda', 'Marcellus', 'DM Serif Display', 'Inter', 'Manrope', 'Jost', 'Montserrat', 'Lato'];

    public function __construct(protected OptimizeImage $images) {}

    public function handle(Tenant $tenant, array $data): Tenant
    {
        abort_if($tenant->isReadOnly(), 423);

        if (isset($data['theme']) && ! in_array($data['theme'], ThemeRegistry::keys(), true)) {
            throw new DomainException(__('validation.in', ['attribute' => 'theme']));
        }
        foreach (['primary', 'accent', 'ink', 'background'] as $color) {
            if (array_key_exists($color, $data) && $data[$color] !== null && SiteSeo::cssColor($data[$color], '') === '') {
                throw new DomainException(__('validation.regex', ['attribute' => $color]));
            }
        }
        foreach (['font_display', 'font_body'] as $font) {
            if (! empty($data[$font]) && ! in_array($data[$font], self::FONTS, true)) {
                throw new DomainException(__('validation.in', ['attribute' => $font]));
            }
        }

        // Hero photography: convert new uploads to responsive WebP (logos keep their format, e.g. PNG/SVG transparency).
        if (! empty($data['hero_path']) && $data['hero_path'] !== $tenant->brand('hero_path')) {
            $data['hero_path'] = $this->images->handle($data['hero_path']);
        }

        $allowed = ['theme', 'primary', 'accent', 'ink', 'background', 'font_display', 'font_body', 'button_style', 'logo_path', 'logo_dark_path', 'favicon_path', 'hero_path', 'about', 'social'];
        $branding = array_replace(Tenant::DEFAULT_BRANDING, $tenant->branding ?? [], array_intersect_key($data, array_flip($allowed)));
        $tenant->update(['branding' => $branding]);

        Cache::forget('tenant-domain:'.optional($tenant->primaryDomain)->domain);
        activity()->performedOn($tenant)->withProperties(['theme' => $branding['theme'] ?? null])->log('website.design.updated');

        return $tenant->fresh();
    }
}
