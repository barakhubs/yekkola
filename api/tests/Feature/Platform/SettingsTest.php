<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Platform\Settings\LearningSettings;
use App\Domain\Platform\Settings\ModerationSettings;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Platform\Settings\ProtectionSettings;
use Laravel\Sanctum\Sanctum;

it('seeds the platform defaults from project-context', function () {
    $commerce = app(CommerceSettings::class);
    $payouts = app(PayoutSettings::class);
    $protection = app(ProtectionSettings::class);
    $catalog = app(CatalogSettings::class);
    $learning = app(LearningSettings::class);
    $moderation = app(ModerationSettings::class);

    expect($commerce->enabled_currencies)->toBe(['USD', 'CDF'])
        ->and($commerce->default_currency)->toBe('USD')
        ->and($commerce->default_revenue_share_bps)->toBe(7_000)
        ->and($commerce->enabled_rails)->toBe(['orange', 'airtel', 'mpesa'])
        ->and($commerce->order_payment_timeout_minutes)->toBe(15)
        ->and($commerce->refund_window_days)->toBe(7)
        ->and($commerce->refund_max_progress_pct)->toBe(20)
        ->and($commerce->separate_payer_enabled)->toBeTrue()
        ->and($payouts->earnings_hold_days)->toBe(7)
        ->and($payouts->schedule)->toBe('monthly')
        ->and($payouts->minimum_minor)->toBe(['USD' => 1_000, 'CDF' => 2_500_000])
        ->and($payouts->platform_pays_disbursement_fees)->toBeTrue()
        ->and($protection->registered_device_limit)->toBe(2)
        ->and($protection->concurrent_web_streams)->toBe(1)
        ->and($protection->offline_license_days)->toBe(14)
        ->and($catalog->free_course_limit)->toBeNull()
        ->and($catalog->max_preview_lessons)->toBe(3)
        ->and($learning->certificate_min_completion_pct)->toBe(100)
        ->and($learning->review_min_progress_pct)->toBe(20)
        ->and($moderation->course_review_sla_hours)->toBe(48)
        ->and($moderation->application_review_sla_hours)->toBe(72);
});

it('audits settings changes with before and after values and the acting admin', function () {
    $admin = User::factory()->create();
    Sanctum::actingAs($admin);

    $settings = app(ProtectionSettings::class);
    $settings->registered_device_limit = 3;
    $settings->save();

    $entry = Activity::query()->where('event', 'settings.updated')->sole();

    expect($entry->log_name)->toBe('audit')
        ->and($entry->subject_type)->toBe('settings:protection')
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties->get('before'))->toBe(['registered_device_limit' => 2])
        ->and($entry->properties->get('after'))->toBe(['registered_device_limit' => 3])
        ->and(app(ProtectionSettings::class)->registered_device_limit)->toBe(3);
});

it('does not audit a save that changes nothing', function () {
    app(ModerationSettings::class)->save();

    expect(Activity::query()->count())->toBe(0);
});
