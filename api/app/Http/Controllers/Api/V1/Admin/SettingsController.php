<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Actions\UpdateSettings;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Platform\Settings\LearningSettings;
use App\Domain\Platform\Settings\ModerationSettings;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;

/**
 * Platform settings (project-context "Platform settings", PRD-09): read and change them; every change is audited
 * and applies going forward only.
 */
final class SettingsController extends Controller
{
    /**
     * @return array{data: array{commerce: array{enabled_currencies: list<string>, default_currency: string, default_revenue_share_bps: int, enabled_gateways: list<string>, enabled_rails: list<string>, price_limits_minor: array<string, array{min: int, max: int}>, order_payment_timeout_minutes: int, refund_window_days: int, refund_max_progress_pct: int, separate_payer_enabled: bool}, payouts: array{earnings_hold_days: int, schedule: string, minimum_minor: array<string, int>, platform_pays_disbursement_fees: bool, payout_method_change_hold_hours: int}, protection: array{registered_device_limit: int, concurrent_web_streams: int, offline_license_days: int, playback_token_ttl_minutes: int}, catalog: array{free_course_limit: int|null, free_media_hours_limit: int|null, max_preview_lessons: int, max_video_upload_mb: int, max_audio_upload_mb: int, max_document_upload_mb: int}, learning: array{certificate_min_completion_pct: int, certificate_requires_quizzes_passed: bool, review_min_progress_pct: int}, moderation: array{course_review_sla_hours: int, application_review_sla_hours: int, application_reapply_cooldown_days: int}}}
     */
    public function show(): array
    {
        $commerce = app(CommerceSettings::class);
        $payouts = app(PayoutSettings::class);
        $protection = app(ProtectionSettings::class);
        $catalog = app(CatalogSettings::class);
        $learning = app(LearningSettings::class);
        $moderation = app(ModerationSettings::class);

        return ['data' => [
            'commerce' => [
                'enabled_currencies' => array_values($commerce->enabled_currencies),
                'default_currency' => $commerce->default_currency,
                'default_revenue_share_bps' => $commerce->default_revenue_share_bps,
                'enabled_gateways' => array_values($commerce->enabled_gateways),
                'enabled_rails' => array_values($commerce->enabled_rails),
                'price_limits_minor' => array_map(fn (array $l) => ['min' => (int) ($l['min'] ?? 0), 'max' => (int) ($l['max'] ?? 0)], $commerce->price_limits_minor),
                'order_payment_timeout_minutes' => $commerce->order_payment_timeout_minutes,
                'refund_window_days' => $commerce->refund_window_days,
                'refund_max_progress_pct' => $commerce->refund_max_progress_pct,
                'separate_payer_enabled' => $commerce->separate_payer_enabled,
            ],
            'payouts' => [
                'earnings_hold_days' => $payouts->earnings_hold_days,
                'schedule' => $payouts->schedule,
                'minimum_minor' => $payouts->minimum_minor,
                'platform_pays_disbursement_fees' => $payouts->platform_pays_disbursement_fees,
                'payout_method_change_hold_hours' => $payouts->payout_method_change_hold_hours,
            ],
            'protection' => [
                'registered_device_limit' => $protection->registered_device_limit,
                'concurrent_web_streams' => $protection->concurrent_web_streams,
                'offline_license_days' => $protection->offline_license_days,
                'playback_token_ttl_minutes' => $protection->playback_token_ttl_minutes,
            ],
            'catalog' => [
                'free_course_limit' => $catalog->free_course_limit,
                'free_media_hours_limit' => $catalog->free_media_hours_limit,
                'max_preview_lessons' => $catalog->max_preview_lessons,
                'max_video_upload_mb' => $catalog->max_video_upload_mb,
                'max_audio_upload_mb' => $catalog->max_audio_upload_mb,
                'max_document_upload_mb' => $catalog->max_document_upload_mb,
            ],
            'learning' => [
                'certificate_min_completion_pct' => $learning->certificate_min_completion_pct,
                'certificate_requires_quizzes_passed' => $learning->certificate_requires_quizzes_passed,
                'review_min_progress_pct' => $learning->review_min_progress_pct,
            ],
            'moderation' => [
                'course_review_sla_hours' => $moderation->course_review_sla_hours,
                'application_review_sla_hours' => $moderation->application_review_sla_hours,
                'application_reapply_cooldown_days' => $moderation->application_reapply_cooldown_days,
            ],
        ]];
    }

    /**
     * Changes any of the groups (each sent whole) and answers with all settings as they now are. A 422 names the
     * setting at fault, including rules across groups (e.g. earnings held at least as long as the refund window).
     *
     * @return array{data: array{commerce: array{enabled_currencies: list<string>, default_currency: string, default_revenue_share_bps: int, enabled_gateways: list<string>, enabled_rails: list<string>, price_limits_minor: array<string, array{min: int, max: int}>, order_payment_timeout_minutes: int, refund_window_days: int, refund_max_progress_pct: int, separate_payer_enabled: bool}, payouts: array{earnings_hold_days: int, schedule: string, minimum_minor: array<string, int>, platform_pays_disbursement_fees: bool, payout_method_change_hold_hours: int}, protection: array{registered_device_limit: int, concurrent_web_streams: int, offline_license_days: int, playback_token_ttl_minutes: int}, catalog: array{free_course_limit: int|null, free_media_hours_limit: int|null, max_preview_lessons: int, max_video_upload_mb: int, max_audio_upload_mb: int, max_document_upload_mb: int}, learning: array{certificate_min_completion_pct: int, certificate_requires_quizzes_passed: bool, review_min_progress_pct: int}, moderation: array{course_review_sla_hours: int, application_review_sla_hours: int, application_reapply_cooldown_days: int}}}
     */
    public function update(UpdateSettingsRequest $request, UpdateSettings $update): array
    {
        $update->handle($request->changes());

        // Fresh instances: the container may hold the ones read before saving.
        foreach (UpdateSettings::GROUPS as $class) {
            app()->forgetInstance($class);
        }

        return $this->show();
    }
}
