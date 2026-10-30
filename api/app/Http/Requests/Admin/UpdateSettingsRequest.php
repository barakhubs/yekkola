<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Any of the six settings groups, each sent whole (the back office saves one section at a time). Rules spanning
 * groups are checked by UpdateSettings.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currencies = Rule::in(array_map(fn (Currency $c) => $c->value, Currency::cases()));

        return [
            'commerce' => ['sometimes', 'array'],
            'commerce.enabled_currencies' => ['required_with:commerce', 'array', 'min:1'],
            'commerce.enabled_currencies.*' => ['distinct', $currencies],
            'commerce.default_currency' => ['required_with:commerce', $currencies],
            'commerce.default_revenue_share_bps' => ['required_with:commerce', 'integer', 'between:0,10000'],
            'commerce.enabled_gateways' => ['required_with:commerce', 'array'],
            'commerce.enabled_gateways.*' => ['distinct', 'string', 'alpha_dash', 'max:32'],
            'commerce.enabled_rails' => ['required_with:commerce', 'array'],
            'commerce.enabled_rails.*' => ['distinct', Rule::enum(MobileMoneyRail::class)],
            'commerce.price_limits_minor' => ['required_with:commerce', 'array'],
            'commerce.price_limits_minor.*.min' => ['integer', 'min:1'],
            'commerce.price_limits_minor.*.max' => ['integer', 'min:1'],
            'commerce.order_payment_timeout_minutes' => ['required_with:commerce', 'integer', 'between:2,120'],
            'commerce.refund_window_days' => ['required_with:commerce', 'integer', 'between:0,90'],
            'commerce.refund_max_progress_pct' => ['required_with:commerce', 'integer', 'between:0,100'],
            'commerce.separate_payer_enabled' => ['required_with:commerce', 'boolean'],

            'payouts' => ['sometimes', 'array'],
            'payouts.earnings_hold_days' => ['required_with:payouts', 'integer', 'between:0,120'],
            'payouts.schedule' => ['required_with:payouts', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'payouts.minimum_minor' => ['required_with:payouts', 'array'],
            'payouts.minimum_minor.*' => ['integer', 'min:1'],
            'payouts.platform_pays_disbursement_fees' => ['required_with:payouts', 'boolean'],
            'payouts.payout_method_change_hold_hours' => ['required_with:payouts', 'integer', 'between:0,720'],

            'protection' => ['sometimes', 'array'],
            'protection.registered_device_limit' => ['required_with:protection', 'integer', 'between:1,10'],
            'protection.concurrent_web_streams' => ['required_with:protection', 'integer', 'between:1,10'],
            'protection.offline_license_days' => ['required_with:protection', 'integer', 'between:1,60'],
            'protection.playback_token_ttl_minutes' => ['required_with:protection', 'integer', 'between:1,1440'],

            'catalog' => ['sometimes', 'array'],
            'catalog.free_course_limit' => ['present_with:catalog', 'nullable', 'integer', 'min:0'],
            'catalog.free_media_hours_limit' => ['present_with:catalog', 'nullable', 'integer', 'min:0'],
            'catalog.max_preview_lessons' => ['required_with:catalog', 'integer', 'between:0,20'],
            'catalog.max_video_upload_mb' => ['required_with:catalog', 'integer', 'between:1,20000'],
            'catalog.max_audio_upload_mb' => ['required_with:catalog', 'integer', 'between:1,5000'],
            'catalog.max_document_upload_mb' => ['required_with:catalog', 'integer', 'between:1,500'],

            'learning' => ['sometimes', 'array'],
            'learning.certificate_min_completion_pct' => ['required_with:learning', 'integer', 'between:1,100'],
            'learning.certificate_requires_quizzes_passed' => ['required_with:learning', 'boolean'],
            'learning.review_min_progress_pct' => ['required_with:learning', 'integer', 'between:0,100'],

            'moderation' => ['sometimes', 'array'],
            'moderation.course_review_sla_hours' => ['required_with:moderation', 'integer', 'between:1,720'],
            'moderation.application_review_sla_hours' => ['required_with:moderation', 'integer', 'between:1,720'],
            'moderation.application_reapply_cooldown_days' => ['required_with:moderation', 'integer', 'between:0,365'],
        ];
    }

    /**
     * Only the groups sent, with only their known keys.
     *
     * @return array<string, array<string, mixed>>
     */
    public function changes(): array
    {
        /** @var array<string, array<string, mixed>> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
