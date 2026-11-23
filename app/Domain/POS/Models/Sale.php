<?php

namespace App\Domain\POS\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\CRM\Models\Client;
use App\Domain\Payments\Contracts\Payable;
use App\Domain\Payments\Models\Payment;
use App\Domain\POS\Enums\SaleStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\URL;

/**
 * A POS ticket. Draft while an online (QR) payment is pending, completed by CompleteSale.
 */
class Sale extends Model implements Payable
{
    use BelongsToBranch, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'business_date' => 'date',
            'discount_meta' => 'array',
            'tips_meta' => 'array',
            'sync_conflicts' => 'array',
            'is_offline' => 'boolean',
            'tax_inclusive' => 'boolean',
            'tax_percent' => 'float',
            'offline_created_at' => 'datetime',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class)->latest('id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class, 'pos_register_id')->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'pos_shift_id');
    }

    public function isCompleted(): bool
    {
        return $this->status !== SaleStatus::Draft;
    }

    public function refundableAmount(): int
    {
        return max(0, (int) $this->total - (int) $this->refunded_total);
    }

    /** Signed public receipt link (WhatsApp / email share). Relative signature: valid on any of the salon's domains. */
    public function receiptUrl(): string
    {
        return url(URL::signedRoute('pos.receipt.public', ['sale' => $this->id], absolute: false));
    }

    public function paymentDescription(): string
    {
        return __('pos.receipt.title').' '.($this->number ?? '#'.$this->id);
    }

    /** Online (QR) payments only settle the Payment row; the cashier completes the sale. */
    public function onPaymentSucceeded(Payment $payment): void {}

    public function onPaymentFailed(Payment $payment): void {}
}
