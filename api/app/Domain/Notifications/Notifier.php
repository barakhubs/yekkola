<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Facades\Notification;

/**
 * Sends catalogue notifications (PRD-10) in each recipient's language. Callers only say what happened; channels,
 * preferences and texts are decided here and in {@see PlatformNotification}.
 */
final class Notifier
{
    /**
     * @param  array<string, string>  $params
     */
    public function user(?User $user, NotificationType $type, string $key, array $params = [], ?string $link = null): void
    {
        if ($user === null || $user->isAnonymised()) {
            return;
        }

        $user->notify((new PlatformNotification($type, $key, $params, $link))->locale($user->locale));
    }

    /**
     * An SMS to a number without an account (a parent paying for a student).
     *
     * @param  array<string, string>  $params
     */
    public function sms(string $phoneE164, NotificationType $type, string $key, array $params = [], string $locale = 'fr'): void
    {
        Notification::route('sms', $phoneE164)->notify((new PlatformNotification($type, $key, $params))->locale($locale));
    }

    public static function money(int $amountMinor, string $currency, string $locale): string
    {
        // Plain spaces: ICU's no-break spaces aren't GSM-7 and would double an SMS's cost.
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', MoneyFormatter::format(Money::of($amountMinor, Currency::from($currency)), $locale));
    }
}
