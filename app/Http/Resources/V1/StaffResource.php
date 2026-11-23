<?php

namespace App\Http\Resources\V1;

use App\Domain\Team\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Staff */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'bio' => $this->bio,
            'photo_url' => $this->photo_path ? asset('storage/'.$this->photo_path) : null,
            'rating' => round((float) $this->rating, 2),
            'ratings_count' => $this->ratings_count,
            'branch_ids' => $this->whenLoaded('branches', fn () => $this->branches->pluck('id')),
            'service_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')),
        ];
    }
}
