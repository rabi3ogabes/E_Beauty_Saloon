<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

if (! function_exists('tenant')) {
    /** The tenant active for this request/job, or null on central domains. */
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}

if (! function_exists('money')) {
    /** Format integer minor units as a localized money string. */
    function money(?int $minor, ?string $currency = null): string
    {
        return Money::of($minor ?? 0, $currency ?? tenant()?->currency ?? config('salon.defaults.currency'))->format();
    }
}

if (! function_exists('safe_html')) {
    /**
     * Minimal allow-list sanitiser for CMS rich text: keeps only the given tags and, on them, only
     * href (http/https/mailto/tel/relative), src (http/https/relative), alt and title attributes.
     */
    function safe_html(?string $html, string $allowedTags = '<p><br><strong><em><b><i><ul><ol><li><a><h2><h3><h4><blockquote>'): string
    {
        $html = strip_tags((string) $html, $allowedTags);

        return (string) preg_replace_callback('/<([a-z0-9]+)(\s[^>]*)?>/i', function ($m) {
            $tag = strtolower($m[1]);
            $keep = [];
            preg_match_all('/([a-z\-:]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[2] ?? '', $attrs, PREG_SET_ORDER);
            foreach ($attrs as $a) {
                $name = strtolower($a[1]);
                $value = html_entity_decode($a[3] !== '' ? $a[3] : ($a[4] !== '' ? $a[4] : ($a[5] ?? '')), ENT_QUOTES);
                $isSafeUrl = (bool) preg_match('#^(https?://|mailto:|tel:|/|\#)#i', trim($value));
                if (($name === 'href' && $tag === 'a' && $isSafeUrl) || ($name === 'src' && $tag === 'img' && preg_match('#^(https?://|/)#i', trim($value)))
                    || in_array($name, ['alt', 'title'], true)) {
                    $keep[] = $name.'="'.e($value).'"';
                }
            }
            if ($tag === 'a') {
                $keep[] = 'rel="noopener nofollow"';
            }

            return '<'.$tag.($keep ? ' '.implode(' ', $keep) : '').'>';
        }, $html);
    }
}
