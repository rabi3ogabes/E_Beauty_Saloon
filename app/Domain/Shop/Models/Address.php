<?php

namespace App\Domain\Shop\Models;

use App\Domain\CRM\Models\Client;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'is_default' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id')->withoutGlobalScope(BranchScope::class);
    }

    public function snapshot(): array
    {
        return $this->only(['label', 'recipient_name', 'phone', 'area', 'street', 'building', 'unit', 'lat', 'lng', 'notes']);
    }

    public function oneLine(): string
    {
        return collect([$this->unit, $this->building, $this->street, $this->area])->filter()->implode(', ');
    }
}
