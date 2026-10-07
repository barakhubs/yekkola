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
        // fake | turnstile (web OTP requests)
        'bot_challenge' => env('BOT_CHALLENGE_DRIVER', 'fake'),
    ],

    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

    'auth' => [
        // Mobile API tokens expire after this many days (users sign in again with an OTP).
        'token_ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 90),
        'otp_ttl_minutes' => 5,
        'otp_max_attempts' => 5,
        'otp_resend_after_seconds' => 60,
        // PRD-01 FR-10
        'otp_per_phone' => ['max' => 3, 'decay_seconds' => 600],
        'otp_per_ip' => ['max' => 10, 'decay_seconds' => 3_600],
        // Grace period before a deletion request anonymises the account (FR-11).
        'deletion_grace_days' => 14,
        'export_ttl_days' => 7,
        // Android SMS Retriever app hash (11 chars) appended to OTP texts once the app exists.
        'sms_retriever_hash' => env('SMS_RETRIEVER_HASH'),
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
