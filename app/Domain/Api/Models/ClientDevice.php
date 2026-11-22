<?php

namespace App\Domain\Api\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mobile app installation of a client (FCM registration token). */
class ClientDevice extends Model
{
    use BelongsToTenant;

    public const PLATFORMS = ['ios', 'android', 'web'];

    protected $table = 'client_devices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Most recently active push token of a client (null when the client has no device). */
    public static function latestTokenFor(Client $client): ?string
    {
        return static::withoutGlobalScopes()->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->value('token');
    }
}
