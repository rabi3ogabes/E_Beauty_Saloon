<?php

namespace App\Domain\Payments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GiftCard extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $g) {
            $g->code ??= static::generateCode();
            $g->balance ??= $g->initial_amount;
        });
    }

    public static function generateCode(): string
    {
        return implode('-', str_split(strtoupper(Str::random(12)), 4));
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->balance > 0 && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
