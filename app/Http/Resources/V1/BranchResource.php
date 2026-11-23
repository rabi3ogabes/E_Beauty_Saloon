<?php

namespace App\Http\Resources\V1;

use App\Domain\Branches\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'address' => $this->address,
            'area' => $this->area,
            'city' => $this->city,
            'location' => $this->lat ? ['lat' => $this->lat, 'lng' => $this->lng] : null,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'timezone' => $this->timezone,
            'opening_hours' => $this->opening_hours,
            'is_open_now' => $this->isOpenNow(),
            'booking_enabled' => $this->booking_enabled,
            'pickup_enabled' => $this->shop_pickup_enabled,
            'cover_url' => $this->cover_path ? asset('storage/'.$this->cover_path) : null,
        ];
    }
}
