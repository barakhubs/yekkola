<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\ConnectionInterface;

/**
 * The student account for a phone number, created if there is none yet (PRD-05 FR-14: a parent pays for a
 * child who may not have signed up). The student signs in later with that number by OTP, as anyone does.
 * Never reveals whether the number already had an account. Same country allowlist as sign-in.
 */
final class FindOrRegisterStudent
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(PhoneNumber $phone, string $locale): User
    {
        /** @var list<int> $allowed */
        $allowed = config('yekkola.auth.otp_allowed_country_codes');
        if (! in_array($phone->countryCode(), $allowed, true)) {
            throw IdentityException::countryNotSupported();
        }

        return $this->db->transaction(function () use ($phone, $locale): User {
            $user = User::query()->where('phone_e164', $phone->e164)->lockForUpdate()->first();
            if ($user !== null) {
                return $user;
            }

            $user = User::query()->createOrFirst(['phone_e164' => $phone->e164], ['locale' => $locale]);
            if ($user->wasRecentlyCreated) {
                $user->assignRole(Role::Student->value);
            }

            return $user;
        });
    }
}
