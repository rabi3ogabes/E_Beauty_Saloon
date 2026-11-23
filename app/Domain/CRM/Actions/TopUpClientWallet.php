<?php

namespace App\Domain\CRM\Actions;

use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Actions\WalletLedger;
use App\Domain\Payments\Models\WalletTransaction;
use App\Support\Exceptions\DomainException;

/** Dashboard wallet top-up (prepaid balance / store credit) through the wallet ledger. */
class TopUpClientWallet
{
    public function __construct(protected WalletLedger $ledger) {}

    public function handle(Client $client, int $amount, string $type = 'topup', ?string $note = null, ?string $idempotencyKey = null): WalletTransaction
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if ($amount <= 0) {
            throw new DomainException(__('workspace.clients.amount_positive'));
        }
        if (! in_array($type, ['topup', 'store_credit'], true)) {
            $type = 'topup';
        }

        $tx = $this->ledger->credit($client, $amount, $type, null, $idempotencyKey, $note);
        activity()->performedOn($client)->causedBy(auth()->user())->withProperties(['amount' => $amount, 'type' => $type])->log('client.wallet_topup');

        return $tx;
    }
}
