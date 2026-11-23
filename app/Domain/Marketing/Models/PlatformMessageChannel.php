<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-shared sender for a channel (central, managed by the super admin) + price per message. */
class PlatformMessageChannel extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean'];
    }

    public static function priceFor(string $channel): int
    {
        return (int) (static::query()->where('channel', $channel)->value('price_per_message') ?? 0);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }
}
