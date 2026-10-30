<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Platform\Settings\PayoutSettings;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = actingAsStaff(Role::Admin);
    $this->settings = fn () => $this->getJson('/api/v1/admin/settings')->assertOk()->json('data');
});

it('saves a group, audits what changed, and answers with every setting', function () {
    $payouts = ($this->settings)()['payouts'];
    $payouts['schedule'] = 'weekly';

    $this->putJson('/api/v1/admin/settings', ['payouts' => $payouts])->assertOk()
        ->assertJsonPath('data.payouts.schedule', 'weekly')
        ->assertJsonPath('data.commerce.default_currency', app(CommerceSettings::class)->default_currency);

    app()->forgetInstance(PayoutSettings::class);
    expect(app(PayoutSettings::class)->schedule)->toBe('weekly');
    $audit = Activity::query()->where('event', 'settings.updated')->sole();
    expect($audit->properties['group'])->toBe('payouts')
        ->and($audit->properties['before'])->toBe(['schedule' => 'monthly'])
        ->and($audit->properties['after'])->toBe(['schedule' => 'weekly'])
        ->and($audit->causer_id)->toBe($this->admin->id);
});

it('refuses settings that contradict each other, saving nothing', function () {
    $all = ($this->settings)();
    $refundWindow = $all['commerce']['refund_window_days'];

    $payouts = $all['payouts'];
    $payouts['earnings_hold_days'] = $refundWindow - 1; // money could be paid out before a refund
    $this->putJson('/api/v1/admin/settings', ['payouts' => $payouts])->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $e) => array_key_exists('payouts.earnings_hold_days', $e));

    $commerce = $all['commerce'];
    unset($commerce['price_limits_minor']['CDF']);
    $this->putJson('/api/v1/admin/settings', ['commerce' => $commerce])->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $e) => array_key_exists('commerce.price_limits_minor.CDF', $e));

    $commerce = $all['commerce'];
    $commerce['enabled_currencies'] = ['CDF'];
    $commerce['default_currency'] = 'USD';
    $this->putJson('/api/v1/admin/settings', ['commerce' => $commerce])->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $e) => array_key_exists('commerce.default_currency', $e));

    $commerce = $all['commerce'];
    $commerce['default_revenue_share_bps'] = 12_000;
    $this->putJson('/api/v1/admin/settings', ['commerce' => $commerce])->assertUnprocessable();

    expect(Activity::query()->where('event', 'settings.updated')->count())->toBe(0)
        ->and(($this->settings)())->toBe($all);
});

it('is for settings managers only', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));

    $this->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->putJson('/api/v1/admin/settings', [])->assertForbidden();
});
