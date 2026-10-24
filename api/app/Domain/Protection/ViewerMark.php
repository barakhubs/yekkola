<?php

declare(strict_types=1);

namespace App\Domain\Protection;

use App\Domain\Identity\Models\User;
use App\Domain\Protection\Stamping\StampText;
use Carbon\CarbonImmutable;

/**
 * Who a copy of the content was given to — never the full phone number (PRD-07 BR-05). Drawn over the
 * player (FR-04) and printed on documents (FR-12).
 */
final class ViewerMark
{
    /** "Marie K. · +243 8•• ••• 123" */
    public static function overlay(User $user): string
    {
        return self::join([User::shortNameOf($user->name), $user->maskedPhone()]);
    }

    public static function stamp(User $user, CarbonImmutable $on): StampText
    {
        $who = self::join([$user->name, $user->maskedPhone()]);

        return new StampText(
            footer: self::join([$who, $on->locale($user->locale)->isoFormat('L'), 'Yekkola']),
            diagonal: $who,
        );
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function join(array $parts): string
    {
        return implode(' · ', array_values(array_filter($parts, fn (?string $p) => $p !== null && $p !== '')));
    }
}
