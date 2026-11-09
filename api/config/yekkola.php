<?php

declare(strict_types=1);

/*
| Yekkola integration drivers. Fake/log drivers are for local, staging, and tests only —
| production refuses to boot with them (App\Providers\IntegrationServiceProvider).
*/

return [

    // Public web app (certificate verification links, PRD-06 FR-10).
    'web_url' => env('FRONTEND_WEB_URL', 'http://localhost:3000'),
    'admin_url' => env('FRONTEND_ADMIN_URL', 'http://localhost:3001'),
    // Shared with the web app: proves its server-side requests (and the visitor IP it forwards, for rate limits).
    'web_server_key' => env('WEB_SERVER_KEY', ''),
    // Shared with the web app: signs "drop these cached pages" calls. Empty = don't call (local without the web app).
    'web_revalidate_secret' => env('WEB_REVALIDATE_SECRET', ''),
    // Demo accounts (DemoCatalogueSeeder) use this range; outside production no SMS is ever sent to it.
    'demo_phone_prefix' => '+24399000',

    'drivers' => [
        // fake | r2 (Cloudflare R2 + FFmpeg encrypted HLS)
        'video' => env('VIDEO_DRIVER', 'fake'),
        // fake | <aggregator> (deferred until the aggregator is chosen)
        'payments' => env('PAYMENT_DRIVER', 'fake'),
        // log | <provider> (deferred until the SMS gateway is chosen)
        'sms' => env('SMS_DRIVER', 'log'),
        // log | fcm (push notifications, PRD-10 FR-04)
        'push' => env('PUSH_DRIVER', 'log'),
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

    // Local/staging: password for the demo staff accounts (DemoUsersSeeder); empty = no password (use SMS or "View as").
    'demo_staff_password' => env('DEMO_STAFF_PASSWORD'),

    // Default super admin (`admin:create-super-admin`): email + password into the back office, phone for SMS codes.
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
        'phone' => env('SUPER_ADMIN_PHONE'),
        'name' => env('SUPER_ADMIN_NAME', 'Super admin'),
    ],

    // Lesson video/audio (R2 video driver, arch §1.3). Upload limits are platform settings (CatalogSettings).
    'media' => [
        // Private R2 disk holding sources and encrypted HLS output (never public).
        'disk' => env('MEDIA_DISK', 'r2-private'),
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
        // Seconds all FFmpeg runs of one asset may take together (the job adds 30 min for R2 transfers).
        'timeout_seconds' => (int) env('MEDIA_TRANSCODE_TIMEOUT', 3 * 3_600),
        // Queue connection for transcoding: `redis-media` (long retry_after) whenever queues run on Redis.
        'queue_connection' => env('MEDIA_QUEUE_CONNECTION', env('QUEUE_CONNECTION') === 'redis' ? 'redis-media' : null),
        // Multipart upload part size (raised automatically for very large files).
        'part_size_mb' => 10,
        // Pre-signed part URLs live this long; the studio asks again when one expires.
        'upload_url_ttl_minutes' => 60,
        'segment_seconds' => 6,
        // Video ladder, highest first; renditions above the source height are skipped (the lowest is always made).
        'renditions' => [
            ['name' => '720p', 'height' => 720, 'video_kbps' => 1_800, 'audio_kbps' => 96],
            ['name' => '480p', 'height' => 480, 'video_kbps' => 900, 'audio_kbps' => 64],
            ['name' => '360p', 'height' => 360, 'video_kbps' => 500, 'audio_kbps' => 64],
        ],
        'audio_kbps' => 64,
        // Burned-in watermark: logo PNG (optional; skipped if missing) + "Yekkola · professor name" text.
        'watermark_logo' => env('MEDIA_WATERMARK_LOGO', resource_path('media/watermark.png')),
        // TTF/OTF for the text; empty = FFmpeg's fontconfig default.
        'watermark_font' => env('MEDIA_WATERMARK_FONT'),
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
        // Provisional — replace with the stored rendition sizes from the R2 driver (TODO 1.6).
        'download_kbps' => [
            'video' => (int) env('DOWNLOAD_ESTIMATE_VIDEO_KBPS', 1_000),
            'audio' => (int) env('DOWNLOAD_ESTIMATE_AUDIO_KBPS', 96),
        ],
        'rail_size' => 12, // courses per home rail
        'max_featured' => 24,
    ],

    // Firebase Cloud Messaging service account (PRD-10 FR-04); only read when PUSH_DRIVER=fcm.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key' => env('FCM_PRIVATE_KEY'),
    ],
    // Staff alerts (PRD-10 FR-09): a payment is "stuck" once it waits this long past the order payment timeout.
    'alerts' => [
        'stuck_payment_grace_minutes' => (int) env('STUCK_PAYMENT_GRACE_MINUTES', 15),
    ],
];
