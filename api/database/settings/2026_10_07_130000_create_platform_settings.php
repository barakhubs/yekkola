<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Seed defaults for every platform setting (project-context.md → Platform settings).
 * Values marked provisional there must be confirmed before launch; admins change them in the back office.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Commerce (PRD-05)
        $this->migrator->add('commerce.enabled_currencies', ['USD', 'CDF']);           // provisional
        $this->migrator->add('commerce.default_currency', 'USD');                       // provisional
        $this->migrator->add('commerce.default_revenue_share_bps', 7_000);              // provisional: 70% professor
        $this->migrator->add('commerce.enabled_gateways', ['fake']);                   // real aggregator added when integrated
        $this->migrator->add('commerce.enabled_rails', ['orange', 'airtel', 'mpesa']);
        $this->migrator->add('commerce.price_limits_minor', [                            // provisional
            'USD' => ['min' => 100, 'max' => 50_000],                                   // 1.00 – 500.00 USD
            'CDF' => ['min' => 250_000, 'max' => 125_000_000],                          // 2,500 – 1,250,000 CDF
        ]);
        $this->migrator->add('commerce.order_payment_timeout_minutes', 15);
        $this->migrator->add('commerce.refund_window_days', 7);
        $this->migrator->add('commerce.refund_max_progress_pct', 20);
        $this->migrator->add('commerce.separate_payer_enabled', true);

        // Payouts (PRD-08)
        $this->migrator->add('payouts.earnings_hold_days', 7);
        $this->migrator->add('payouts.schedule', 'monthly');                            // provisional
        $this->migrator->add('payouts.minimum_minor', ['USD' => 1_000, 'CDF' => 2_500_000]); // provisional: 10 USD / 25,000 CDF
        $this->migrator->add('payouts.platform_pays_disbursement_fees', true);          // provisional
        $this->migrator->add('payouts.payout_method_change_hold_hours', 48);

        // Content protection (PRD-07)
        $this->migrator->add('protection.registered_device_limit', 2);
        $this->migrator->add('protection.concurrent_web_streams', 1);
        $this->migrator->add('protection.offline_license_days', 14);
        $this->migrator->add('protection.playback_token_ttl_minutes', 360);

        // Catalogue & authoring (PRD-03, PRD-04)
        $this->migrator->add('catalog.free_course_limit', null);
        $this->migrator->add('catalog.free_media_hours_limit', null);
        $this->migrator->add('catalog.max_preview_lessons', 3);
        $this->migrator->add('catalog.max_video_upload_mb', 4_096);
        $this->migrator->add('catalog.max_audio_upload_mb', 500);
        $this->migrator->add('catalog.max_document_upload_mb', 50);

        // Learning (PRD-06)
        $this->migrator->add('learning.certificate_min_completion_pct', 100);
        $this->migrator->add('learning.certificate_requires_quizzes_passed', true);
        $this->migrator->add('learning.review_min_progress_pct', 20);

        // Moderation (PRD-02, PRD-09)
        $this->migrator->add('moderation.course_review_sla_hours', 48);                 // provisional
        $this->migrator->add('moderation.application_review_sla_hours', 72);            // provisional
        $this->migrator->add('moderation.application_reapply_cooldown_days', 30);
    }
};
