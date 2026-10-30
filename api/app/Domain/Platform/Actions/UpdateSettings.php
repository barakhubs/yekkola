<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Settings\CatalogSettings;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Platform\Settings\LearningSettings;
use App\Domain\Platform\Settings\ModerationSettings;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelSettings\Settings;

/**
 * Platform settings changed from the back office (project-context "Platform settings", PRD-09). Each group is sent
 * whole; the rules that span settings are checked on the would-be result first — every enabled currency has price
 * limits and a payout minimum, the default currency is enabled, earnings are held at least as long as refunds can
 * be asked for, price limits fit the currency's step — and only then are the groups saved, in one transaction.
 * A refused change touches nothing, not even the settings already loaded. Every save is audited by
 * AuditSettingsChange. Changes apply going forward only: past orders, prices, splits and ledger entries keep their
 * snapshots.
 */
final class UpdateSettings
{
    /** @var array<string, class-string<Settings>> */
    public const GROUPS = [
        'commerce' => CommerceSettings::class,
        'payouts' => PayoutSettings::class,
        'protection' => ProtectionSettings::class,
        'catalog' => CatalogSettings::class,
        'learning' => LearningSettings::class,
        'moderation' => ModerationSettings::class,
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  array<string, array<string, mixed>>  $changes  Group name → that group's values.
     */
    public function handle(array $changes): void
    {
        $merged = [];
        foreach (['commerce', 'payouts'] as $name) {
            $merged[$name] = array_replace(app(self::GROUPS[$name])->toArray(), $changes[$name] ?? []);
        }
        $this->assertConsistent($merged['commerce'], $merged['payouts']);

        $this->db->transaction(function () use ($changes): void {
            foreach ($changes as $name => $values) {
                app(self::GROUPS[$name])->fill($values)->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $commerce
     * @param  array<string, mixed>  $payouts
     */
    private function assertConsistent(array $commerce, array $payouts): void
    {
        /** @var list<string> $enabled */
        $enabled = $commerce['enabled_currencies'];
        /** @var array<string, array{min?: int, max?: int}> $limits */
        $limits = $commerce['price_limits_minor'];
        /** @var array<string, int> $minimums */
        $minimums = $payouts['minimum_minor'];

        $errors = [];
        if (! in_array($commerce['default_currency'], $enabled, true)) {
            $errors['commerce.default_currency'] = __('validation.settings.default_currency');
        }
        foreach ($enabled as $code) {
            $step = Currency::from($code)->collectionStepMinor();
            $limit = $limits[$code] ?? null;
            if ($limit === null || ! isset($limit['min'], $limit['max'])) {
                $errors["commerce.price_limits_minor.{$code}"] = __('validation.settings.price_limits_missing', ['currency' => $code]);
            } elseif ($limit['min'] > $limit['max'] || $limit['min'] % $step !== 0 || $limit['max'] % $step !== 0) {
                $errors["commerce.price_limits_minor.{$code}"] = __('validation.settings.price_limits_invalid', ['currency' => $code]);
            }
            if (! isset($minimums[$code])) {
                $errors["payouts.minimum_minor.{$code}"] = __('validation.settings.payout_minimum_missing', ['currency' => $code]);
            }
        }
        if ((int) $payouts['earnings_hold_days'] < (int) $commerce['refund_window_days']) {
            $errors['payouts.earnings_hold_days'] = __('validation.settings.hold_shorter_than_refund_window', ['days' => $commerce['refund_window_days']]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
