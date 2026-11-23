<?php

namespace App\Domain\Marketing\Services;

use App\Domain\CRM\Models\Client;

/** Replaces {first_name} {name} {salon} {points} {tier} {referral_link} and any extra {key} placeholders. */
class MessageRenderer
{
    public function render(?string $template, Client $client, array $extra = []): string
    {
        $vars = array_merge([
            'first_name' => $client->firstName(),
            'name' => $client->name,
            'salon' => tenant()?->name,
            'points' => number_format((int) $client->loyalty_points),
            'tier' => __('marketing.loyalty.tiers.'.($client->loyalty_tier ?: 'silver')),
            'referral_link' => url('/r/'.$client->referral_code),
            'link' => url('/book'),
        ], $extra);

        return strtr((string) $template, collect($vars)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());
    }

    /** Pick the client's locale from a {"en":..,"ar":..} map, falling back to English / first value. */
    public function localized(array|string|null $value, ?string $locale): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$locale ?? 'en'] ?? null ?: ($value['en'] ?? null) ?: (collect($value)->filter()->first() ?? ''));
    }
}
