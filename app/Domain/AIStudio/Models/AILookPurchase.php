<?php

namespace App\Domain\AIStudio\Models;

use App\Domain\AIStudio\Actions\ClientQuota;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\Payment;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A client buying extra AI looks from the salon (paid through the salon's own gateway). */
class AILookPurchase extends Model implements Payable
{
    use BelongsToTenant;

    protected $table = 'ai_look_purchases';

    protected $guarded = ['id'];

    public function paymentDescription(): string
    {
        return __('ai_studio.purchase.description', ['count' => $this->looks]);
    }

    public function onPaymentSucceeded(Payment $payment): void
    {
        if ($this->status === 'paid') {
            return;
        }
        $this->update(['status' => 'paid']);
        app(ClientQuota::class)->addPaid($this->tenant_id, $this->subject, $this->looks, $this->client_id);
    }

    public function onPaymentFailed(Payment $payment): void
    {
        $this->update(['status' => 'failed']);
    }
}
