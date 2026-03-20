<?php

return [
    'mode' => env('METIS_MODE', 'embedded'),  // 'standalone' | 'embedded'

    'gating' => [
        'enabled' => env('METIS_GATING', true),
        'free_lookups' => 1,
        'require_business_email' => true,
        'free_email_domains' => [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'yahoo.com',
            'yahoo.dk', 'outlook.com', 'outlook.dk', 'icloud.com',
            'protonmail.com', 'proton.me', 'live.com', 'live.dk',
            'me.com', 'mail.com', 'aol.com', 'gmx.com', 'gmx.dk',
            'yandex.com', 'zoho.com', 'fastmail.com',
        ],
    ],

    'rate_limits' => [
        'anonymous' => 1,          // lookups per hour
        'verified' => 10,          // lookups per hour
        'code_per_email' => 3,     // verification codes per email per hour
        'code_per_ip' => 5,        // distinct emails per IP per hour
    ],

    'admin' => [
        'enabled' => env('METIS_ADMIN', false),
        'auth_method' => 'criipto',
        'allowed_cprs' => env('METIS_ADMIN_CPRS', ''),
        'notify_email' => env('METIS_NOTIFY_EMAIL'),
    ],

    'registry_api' => [
        'url' => env('REGISTRY_API_URL'),
        'key' => env('REGISTRY_API_KEY'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],
];
