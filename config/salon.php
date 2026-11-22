<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    | "Luxe Salon OS" is the working name. Change PLATFORM_NAME to rebrand.
    */
    'name' => env('PLATFORM_NAME', 'Luxe Salon OS'),

    // Base domain used for tenant subdomains: {slug}.{base_domain}
    'base_domain' => env('PLATFORM_BASE_DOMAIN', 'localhost'),

    // Domains that serve the platform website + /admin (never a tenant).
    'central_domains' => array_filter(array_map('trim', explode(',', env('PLATFORM_CENTRAL_DOMAINS', 'localhost,127.0.0.1')))),

    // Header used by mobile/API clients to pick a tenant.
    'tenant_header' => 'X-Tenant',

    'defaults' => [
        'country' => 'QA',
        'currency' => 'QAR',
        'timezone' => 'Asia/Qatar',
        'locale' => 'en',
        'tax_percent' => 0,
    ],

    'locales' => ['en' => 'English', 'ar' => 'العربية'],
    'rtl_locales' => ['ar'],

    // Days a tenant keeps read-only access after expiry before being locked.
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),

    // Renewal reminders sent N days before subscription end.
    'renewal_reminder_days' => [7, 3, 1],

    'billing_cycles' => [1, 3, 6, 12],

    // Sellable modules (keys are stable identifiers used in Pennant features: module:{key}).
    'modules' => [
        'booking' => ['icon' => 'heroicon-o-calendar-days', 'pricing_mode' => 'per_branch'],
        'pos' => ['icon' => 'heroicon-o-calculator', 'pricing_mode' => 'per_branch'],
        'shop' => ['icon' => 'heroicon-o-shopping-bag', 'pricing_mode' => 'per_account'],
        'marketing' => ['icon' => 'heroicon-o-megaphone', 'pricing_mode' => 'per_account'],
        'ai_studio' => ['icon' => 'heroicon-o-sparkles', 'pricing_mode' => 'per_account'],
        'website' => ['icon' => 'heroicon-o-globe-alt', 'pricing_mode' => 'per_account'],
        'loyalty' => ['icon' => 'heroicon-o-gift', 'pricing_mode' => 'per_account'],
    ],

    'domains' => [
        // caddy | cloudflare | null
        'provisioner' => env('DOMAIN_PROVISIONER', 'null'),
        'cname_target' => env('DOMAIN_CNAME_TARGET', 'edge.luxesalon.app'),
        'a_record' => env('DOMAIN_A_RECORD', '203.0.113.10'),
        'cloudflare' => [
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
        ],
    ],

    'impersonation' => [
        'token_ttl_seconds' => 60,
    ],
];
