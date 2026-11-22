<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Models\ClientPackage;
use App\Domain\Marketing\Models\Package;
use App\Domain\Payments\Actions\CreateOnlinePayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sells a package to a client.
 *  - online: creates a pending ClientPackage + hosted payment (activated by Payable::onPaymentSucceeded)
 *  - pos / dashboard: the caller already took the money → active immediately
 *
 * @return array{0: ClientPackage, 1: ?Payment}
 */
class PurchasePackage
{
    public function __construct(protected CreateOnlinePayment $payments, protected ActivateClientPackage $activate) {}

    public function handle(Package $package, Client $client, string $channel = 'online', ?string $returnUrl = null): array
    {
        if (! $package->is_active || ($channel === 'online' && ! $package->sellable_online) || ($channel === 'pos' && ! $package->sellable_pos)) {
            throw new DomainException(__('marketing.packages.errors.not_available'));
        }
        $price = $package->loadMissing('items.itemable')->effectivePrice();

        return DB::transaction(function () use ($package, $client, $channel, $returnUrl, $price) {
            $clientPackage = ClientPackage::create([
                'client_id' => $client->id, 'package_id' => $package->id, 'status' => 'pending',
                'sessions_total' => $package->sessions_count, 'sessions_remaining' => $package->sessions_count,
                'price_paid' => $price, 'currency' => tenant()?->currency ?? 'QAR', 'channel' => $channel,
            ]);

            if ($channel !== 'online' || $price === 0) {
                return [$this->activate->handle($clientPackage, $price), null];
            }

            $gateway = TenantPaymentGateway::for('shop')->first(fn (TenantPaymentGateway $g) => $g->isOnline());
            if (! $gateway) {
                throw new DomainException(__('marketing.errors.no_online_gateway'));
            }
            $payment = $this->payments->handle($clientPackage, $gateway, $price, $returnUrl ?? url('/account/packages'),
                ['name' => $client->name, 'phone' => $client->phone, 'email' => $client->email], 'payment', null, $client->id);

            return [$clientPackage, $payment];
        });
    }
}
