<?php

/*
|--------------------------------------------------------------------------
| AI Beauty Studio
|--------------------------------------------------------------------------
| Platform-level defaults. Provider rows (keys, priorities, models) live in the
| `ai_providers` table and are managed by the super admin in /admin; the values
| below are only used to bootstrap those rows and as fallbacks.
*/

return [
    // Seconds before a provider call is considered timed out (then the router falls back).
    'timeout' => (int) env('AI_TIMEOUT', 60),

    // Queue used by RunAIJob.
    'queue' => env('AI_QUEUE', 'default'),

    // Value of one credit in platform currency minor units (QAR dirham). Used for the margin report.
    'credit_value_minor' => (int) env('AI_CREDIT_VALUE_MINOR', 10),

    // Provider costs are tracked in USD cents; convert to platform currency for margins.
    'usd_to_platform_rate' => (float) env('AI_USD_RATE', 3.64),

    // Photos are deleted after N days unless the client saved the look.
    'retention_days' => (int) env('AI_RETENTION_DAYS', 7),

    // Consent text version shown on the consent screen (bump to force re-consent).
    'consent_version' => '2026-07',

    // Max upload size in KB and accepted mime types.
    'max_upload_kb' => 8192,
    'mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    // Per-client / per-device rate limit (attempts per hour), on top of monthly free tries.
    'rate_limit_per_hour' => (int) env('AI_RATE_LIMIT', 12),

    'providers' => [
        'anthropic' => [
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_key' => env('AI_ANTHROPIC_API_KEY'),
            'version' => '2023-06-01',
            'models' => ['text' => env('AI_ANTHROPIC_MODEL', 'claude-opus-5-5')],
        ],
        'gemini' => [
            'base_url' => env('AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('AI_GEMINI_API_KEY'),
            'models' => ['image' => env('AI_GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'), 'text' => env('AI_GEMINI_TEXT_MODEL', 'gemini-2.5-pro')],
        ],
        'openai' => [
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('AI_OPENAI_API_KEY'),
            'models' => ['image' => env('AI_OPENAI_IMAGE_MODEL', 'gpt-image-1'), 'text' => env('AI_OPENAI_TEXT_MODEL', 'gpt-5')],
        ],
        'openrouter' => [
            'base_url' => env('AI_OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('AI_OPENROUTER_API_KEY'),
            'models' => ['image' => env('AI_OPENROUTER_IMAGE_MODEL', 'google/gemini-3-pro-image,openai/gpt-5-image-mini'), 'text' => env('AI_OPENROUTER_TEXT_MODEL', 'google/gemini-2.5-pro')],
        ],
        'higgsfield' => [
            'base_url' => env('AI_HIGGSFIELD_BASE_URL', 'https://platform.higgsfield.ai/v1'),
            'api_key' => env('AI_HIGGSFIELD_API_KEY'),
            'models' => ['image' => env('AI_HIGGSFIELD_IMAGE_MODEL', 'soul'), 'video' => env('AI_HIGGSFIELD_VIDEO_MODEL', 'dop')],
        ],
    ],

    // Web push (salon PWA). Keys are generated with any VAPID tool; sending is stubbed until a sender is bound.
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:hello@luxesalon.app'),
    ],
];
