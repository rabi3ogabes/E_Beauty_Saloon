<?php

namespace App\Http\Resources\V1;

use App\Domain\CRM\Models\Client;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'birthday' => $this->birthday?->toDateString(),
            'locale' => $this->locale,
            'wallet_balance' => Money::of((int) $this->wallet_balance, tenant()?->currency ?? 'QAR'),
            'loyalty' => ['points' => $this->loyalty_points, 'tier' => $this->loyalty_tier],
            'referral_code' => $this->referral_code,
            'consents' => collect(Client::CHANNELS)->mapWithKeys(fn ($c) => [$c => $this->hasConsent($c)]),
        ];
    }
}
