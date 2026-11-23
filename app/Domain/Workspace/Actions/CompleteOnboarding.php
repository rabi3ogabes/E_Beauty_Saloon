<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Actions\ImportServiceTemplates;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Owner onboarding wizard (first login): business details -> first branch -> branding ->
 * services quick-import -> payment method. Sets tenants.onboarded_at.
 *
 * $data keys: business{name, legal_name, phone, email, tax_number, default_locale},
 *             branch{name{en,ar}, address{en,ar}, city, area, lat, lng, phone, whatsapp, opening_hours},
 *             branding{primary, accent, font_display, font_body, button_style, logo_path},
 *             templates[], gateway{driver, credentials{}, mode, enabled_for[]}|null
 */
class CompleteOnboarding
{
    public function __construct(
        protected CreateBranch $createBranch,
        protected UpdateBranding $branding,
        protected ImportServiceTemplates $templates,
        protected SavePaymentGateway $gateways,
    ) {}

    public function handle(Tenant $tenant, array $data): Tenant
    {
        abort_if($tenant->isReadOnly(), 423, __('salon.errors.read_only'));

        DB::transaction(function () use ($tenant, $data) {
            $business = array_filter(Arr::only($data['business'] ?? [], ['name', 'legal_name', 'phone', 'email', 'tax_number', 'default_locale']), 'filled');
            if ($business) {
                $tenant->update($business);
            }

            if ($branchData = array_filter($data['branch'] ?? [], fn ($v) => $v !== null)) {
                $branchData = Arr::only($branchData, ['name', 'address', 'city', 'area', 'lat', 'lng', 'phone', 'whatsapp', 'email', 'opening_hours']);
                $existing = Branch::query()->withoutGlobalScope('allowed_branches')->orderBy('id')->first();
                if ($existing) {
                    $existing->update($branchData);
                } elseif (filled($branchData['name']['en'] ?? null)) {
                    $this->createBranch->handle($branchData);
                }
            }

            if ($brand = array_filter($data['branding'] ?? [], fn ($v) => $v !== null && $v !== '')) {
                $this->branding->handle($tenant, $brand);
            }

            if (! empty($data['templates'])) {
                $this->templates->handle((array) $data['templates']);
            }

            if (filled($data['gateway']['driver'] ?? null)) {
                $this->gateways->handle(null, ($data['gateway'] ?? []) + ['is_active' => true, 'enabled_for' => ['booking', 'shop', 'pos', 'deposits']]);
            }

            $tenant->forceFill(['onboarded_at' => now()])->save();
        });

        activity()->performedOn($tenant)->causedBy(auth()->user())->log('tenant.onboarded');

        return $tenant->refresh();
    }
}
