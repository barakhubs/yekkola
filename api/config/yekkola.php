<?php

declare(strict_types=1);

/*
| Yekkola integration drivers. Fake/log drivers are for local, staging, and tests only —
| production refuses to boot with them (App\Providers\IntegrationServiceProvider).
*/

return [

    // Public web app (certificate verification links, PRD-06 FR-10).
    'web_url' => env('FRONTEND_WEB_URL', 'http://localhost:3000'),

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
        // Circuit breaker on total OTP SMS per hour (SMS pumping protection).
        'otp_global_hourly_budget' => (int) env('OTP_GLOBAL_HOURLY_BUDGET', 2_000),
        // Countries we send OTP SMS to (calling codes). Provisional: DRC only — blocks premium-rate pumping.
        'otp_allowed_country_codes' => array_map('intval', explode(',', (string) env('OTP_ALLOWED_COUNTRY_CODES', '243'))),
        // Wrong codes per number per 24 h, across all challenges, before the number is locked.
        'otp_max_failures_per_day' => 15,
        // Phone change requires a sign-in this recent.
        'reauth_minutes' => 15,
        // Grace period before a deletion request anonymises the account (FR-11).
        'deletion_grace_days' => 14,
        'export_ttl_days' => 7,
        // Android SMS Retriever app hash (11 chars) appended to OTP texts once the app exists.
        'sms_retriever_hash' => env('SMS_RETRIEVER_HASH'),
    ],

    // Secret used to sign fake webhooks. Required outside local/testing (no committed default).
    'fake_webhook_secret' => env('FAKE_WEBHOOK_SECRET'),

    // Current versions of legal texts users must accept (PRD-02 FR-10). Bump to require re-acceptance.
    'terms' => [
        'professor_agreement' => env('TERMS_PROFESSOR_AGREEMENT_VERSION', '2026-10-draft'),
    ],

    'professors' => [
        // "Needs info" applications left unanswered this long expire (PRD-02 §7).
        'needs_info_expiry_days' => 30,
        'max_credential_upload_kb' => 10_240,
        'max_kyc_upload_kb' => 8_192,
        'max_avatar_upload_kb' => 5_120,
    ],

    // Public files (avatars): `public` in development, an S3/R2 disk with public URLs in staging/production.
    'public_disk' => env('PUBLIC_DISK', 'public'),

    // Private files (data exports, later KYC): `local` in development, an S3/R2 disk in staging/production.
    'private_disk' => env('PRIVATE_DISK', 'local'),

    // Content protection (PRD-07). Per-account limits and token lifetimes are platform settings (ProtectionSettings).
    'protection' => [
        // Web players send a heartbeat this often; a stream without one for `stream_stale_seconds` no longer counts.
        'stream_heartbeat_seconds' => 30,
        'stream_stale_seconds' => 90,
        // Signed download links for stamped documents.
        'document_link_ttl_minutes' => 10,
    ],

    // Local/staging seed: phone number (one you control) for the demo super admin. Empty = not seeded.
    'demo_admin_phone' => env('DEMO_ADMIN_PHONE'),

    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'signing_key_id' => env('MUX_SIGNING_KEY_ID'),
        'signing_private_key' => env('MUX_SIGNING_PRIVATE_KEY'),
        'webhook_secret' => env('MUX_WEBHOOK_SECRET'),
        'drm_configuration_id' => env('MUX_DRM_CONFIGURATION_ID'),
        // Burned-in watermark image (Yekkola logo PNG, public URL) applied to every uploaded video.
        'watermark_url' => env('MUX_WATERMARK_URL'),
    ],

    'checkout' => [
        // Buying for another student (PRD-05 FR-14). Provisional — confirm (TODO 1.7c).
        'orders_per_student_number_per_day' => 10, // by anyone other than the student
        'students_per_buyer_per_day' => 20,        // distinct numbers one account may buy for
        'guest_orders_per_ip_per_hour' => 10,
        'guest_token_days' => 30,                  // how long a guest can follow their order
    ],

    'catalogue' => [
        // Estimated download size at the lowest offline tier (PRD-04 FR-05): duration × bitrate.
        // Provisional — confirm against real Mux renditions in the Mux spike (TODO 1.6).
        'download_kbps' => [
            'video' => (int) env('DOWNLOAD_ESTIMATE_VIDEO_KBPS', 1_000),
            'audio' => (int) env('DOWNLOAD_ESTIMATE_AUDIO_KBPS', 96),
        ],
        'rail_size' => 12, // courses per home rail
        'max_featured' => 24,
    ],

];
