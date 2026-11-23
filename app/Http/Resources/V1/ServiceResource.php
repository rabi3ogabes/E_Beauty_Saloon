<?php

namespace App\Http\Resources\V1;

use App\Domain\Catalog\Models\Service;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $branch = $request->attributes->get('api_branch');
        $currency = tenant()?->currency ?? 'QAR';

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'category_id' => $this->service_category_id,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->durationAt($branch),
            'price' => Money::of($this->priceAt($branch), $currency),
            'original_price' => $this->sale_price ? Money::of((int) $this->price, $currency) : null,
            'gender' => $this->gender,
            'image_url' => $this->image_path ? asset('storage/'.$this->image_path) : null,
            'online_bookable' => $this->online_bookable,
            'deposit' => $this->deposit_type ? ['type' => $this->deposit_type, 'value' => $this->deposit_value] : null,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'duration_minutes' => $v->duration_minutes, 'price' => Money::of($v->price, $currency),
            ])),
            'addons' => $this->whenLoaded('addons', fn () => $this->addons->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'duration_minutes' => $a->duration_minutes, 'price' => Money::of($a->price, $currency),
            ])),
        ];
    }
}
