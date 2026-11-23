<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Workspace\Branding\Color;
use App\Support\Exceptions\DomainException;

/**
 * Saves tenant branding (tenants.branding) or a per-branch override (branches.branding).
 * Only known keys are stored; colours are validated; null values in a branch override mean "inherit".
 */
class UpdateBranding
{
    public const EXTRA_KEYS = ['email_header', 'accent'];

    public const SOCIALS = ['instagram', 'tiktok', 'snapchat', 'x', 'facebook', 'youtube', 'whatsapp'];

    public function handle(Tenant $tenant, array $data, ?Branch $branch = null): array
    {
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        $allowed = array_merge(array_keys(Tenant::DEFAULT_BRANDING), self::EXTRA_KEYS);
        $clean = array_intersect_key($data, array_flip($allowed));

        foreach (['primary', 'accent', 'ink', 'background'] as $color) {
            if (filled($clean[$color] ?? null) && ! Color::isHex($clean[$color])) {
                throw new DomainException(__('workspace.branding.invalid_color'));
            }
        }
        if (isset($clean['button_style']) && ! in_array($clean['button_style'], ['pill', 'rounded'], true)) {
            $clean['button_style'] = 'pill';
        }
        if (isset($clean['social'])) {
            $clean['social'] = array_filter(array_intersect_key((array) $clean['social'], array_flip(self::SOCIALS)), 'filled');
        }
        if (isset($clean['about']) && is_array($clean['about'])) {
            $clean['about'] = array_filter($clean['about'], 'filled') ?: null;
        }

        if ($branch) {
            $merged = array_filter(array_replace($branch->branding ?? [], $clean), fn ($v) => $v !== null && $v !== '' && $v !== []);
            $branch->update(['branding' => $merged ?: null]);
            activity()->performedOn($branch)->causedBy(auth()->user())->withProperties(['keys' => array_keys($clean)])->log('branding.branch_updated');

            return $merged;
        }

        $merged = array_replace($tenant->branding ?? [], $clean);
        $tenant->update(['branding' => $merged]);
        activity()->performedOn($tenant)->causedBy(auth()->user())->withProperties(['keys' => array_keys($clean)])->log('branding.updated');

        return $merged;
    }
}
