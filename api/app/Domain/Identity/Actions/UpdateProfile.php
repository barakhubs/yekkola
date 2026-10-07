<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

/**
 * Updates the user's own profile (PRD-01 FR-04). A changed email becomes unverified.
 */
final class UpdateProfile
{
    /**
     * @param  array{name?: string, email?: string|null, locale?: string, province_id?: string|null, city?: string|null}  $data
     */
    public function handle(User $user, array $data): User
    {
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();

        return $user;
    }
}
