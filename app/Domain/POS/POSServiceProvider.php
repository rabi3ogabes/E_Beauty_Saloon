<?php

namespace App\Domain\POS;

use App\Domain\Booking\Models\Booking;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\POS\Models\Commission;
use App\Domain\POS\Models\Register;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Support\SellableRegistry;
use App\Domain\Team\Models\Staff;
use App\Support\Exceptions\DomainException;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SellableRegistry::class, function () {
            $registry = new SellableRegistry;

            $registry->register('service', function (int $id, Branch $branch, ?int $staffId = null) {
                $service = Service::with('branches')->find($id) ?? throw new DomainException(__('pos.errors.unknown_item'));
                $staff = $staffId ? Staff::withoutGlobalScopes()->with('services')->find($staffId) : null;

                return ['model' => $service, 'name' => (string) $service->name, 'unit_price' => $service->priceAt($branch, $staff), 'tax_percent' => null];
            });

            $registry->register('product', function (int $id, Branch $branch) {
                $variant = ProductVariant::with('product')->find($id) ?? throw new DomainException(__('pos.errors.unknown_item'));

                return [
                    'model' => $variant, 'name' => $variant->displayName(), 'unit_price' => $variant->effectivePrice(),
                    'tax_percent' => $variant->product?->tax_percent, 'track_stock' => (bool) ($variant->product?->track_stock ?? true),
                ];
            });

            return $registry;
        });
    }

    public function boot(): void
    {
        Booking::resolveRelationUsing('sale', fn (Booking $booking) => $booking->belongsTo(Sale::class, 'sale_id'));
        Client::resolveRelationUsing('sales', fn (Client $client) => $client->hasMany(Sale::class)->latest('completed_at'));
        Staff::resolveRelationUsing('commissions', fn (Staff $staff) => $staff->hasMany(Commission::class));
        Branch::resolveRelationUsing('registers', fn (Branch $branch) => $branch->hasMany(Register::class));

        // PWA head tags + POS styles/offline script on every /pos panel page.
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn (): string => Filament::getCurrentPanel()?->getId() === 'pos' && tenant()
            ? view('pos.partials.head')->render() : '');
    }
}
