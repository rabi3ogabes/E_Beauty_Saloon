<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim($c->code)));
    }

    public function isRedeemable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->used_count < $this->max_uses);
    }

    /** Human readable value: "20%" or "QAR 50.00". `value` for percent is basis points. */
    public function displayValue(string $currency = 'QAR'): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format($this->value / 100, 2), '0'), '.').'%'
            : money($this->value, $currency);
    }
}
