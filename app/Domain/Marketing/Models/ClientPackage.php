<?php

namespace App\Domain\Marketing\Models;

use App\Domain\CRM\Models\Client;
use App\Domain\Marketing\Actions\ActivateClientPackage;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\Payment;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A package owned by a client. Online purchases stay "pending" until the payment succeeds. */
class ClientPackage extends Model implements Payable
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'expires_at' => 'datetime', 'expiry_reminded_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ClientPackageUsage::class)->latest('used_at');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->sessions_remaining > 0 && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function paymentDescription(): string
    {
        return __('marketing.packages.payment_description', ['name' => $this->package?->getTranslation('name', app()->getLocale())]);
    }

    public function onPaymentSucceeded(Payment $payment): void
    {
        app(ActivateClientPackage::class)->handle($this->fresh() ?? $this, $payment->amount);
    }

    public function onPaymentFailed(Payment $payment): void
    {
        if ($this->status === 'pending') {
            $this->update(['status' => 'cancelled']);
        }
    }
}
