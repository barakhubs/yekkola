<?php

declare(strict_types=1);

/*
| Yekkola integration drivers. Fake/log drivers are for local, staging, and tests only —
| production refuses to boot with them (App\Providers\IntegrationServiceProvider).
*/

return [

    'drivers' => [
        // fake | mux (mux driver lands with Phase 1.5 authoring)
        'video' => env('VIDEO_DRIVER', 'fake'),
        // fake | <aggregator> (deferred until the aggregator is chosen)
        'payments' => env('PAYMENT_DRIVER', 'fake'),
        // log | <provider> (deferred until the SMS gateway is chosen)
        'sms' => env('SMS_DRIVER', 'log'),
    ],

    // Secret used to sign fake webhooks. Required outside local/testing (no committed default).
    'fake_webhook_secret' => env('FAKE_WEBHOOK_SECRET'),

    // Local/staging seed: phone number (one you control) for the demo super admin. Empty = not seeded.
    'demo_admin_phone' => env('DEMO_ADMIN_PHONE'),

    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'signing_key_id' => env('MUX_SIGNING_KEY_ID'),
        'signing_private_key' => env('MUX_SIGNING_PRIVATE_KEY'),
        'webhook_secret' => env('MUX_WEBHOOK_SECRET'),
        'drm_configuration_id' => env('MUX_DRM_CONFIGURATION_ID'),
    ],

];
