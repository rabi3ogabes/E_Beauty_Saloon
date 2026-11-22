<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use Illuminate\Support\Facades\Schema;

/**
 * Data-subject export (GDPR / Qatar PDPPL). Modules that store client data add their own keys by
 * listening to nothing — we read known tables defensively so this works with any module set.
 */
class ExportClientData
{
    public function handle(Client $client): array
    {
        $data = [
            'profile' => $client->only(['name', 'phone', 'email', 'gender', 'birthday', 'locale', 'source', 'created_at']),
            'consents' => $client->consents()->get(['channel', 'granted', 'source', 'updated_at']),
            'wallet' => $client->walletTransactions()->get(['type', 'amount', 'balance_after', 'note', 'created_at']),
            'loyalty' => $client->loyaltyEntries()->get(['type', 'points', 'balance_after', 'note', 'created_at']),
        ];

        foreach (['bookings' => 'client_id', 'orders' => 'client_id', 'sales' => 'client_id', 'client_looks' => 'client_id', 'reviews' => 'client_id'] as $table => $fk) {
            if (Schema::hasTable($table)) {
                $data[$table] = \DB::table($table)->where($fk, $client->id)->where('tenant_id', $client->tenant_id)->get();
            }
        }

        activity()->performedOn($client)->log('client.data_exported');

        return $data;
    }
}
