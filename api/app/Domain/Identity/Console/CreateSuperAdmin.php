<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Creates (or updates) the default super admin from options or environment variables — never from a password in
 * the code: SUPER_ADMIN_EMAIL, SUPER_ADMIN_PASSWORD, SUPER_ADMIN_PHONE, SUPER_ADMIN_NAME. The account must choose its
 * own password at first sign-in. It can also sign in with an SMS code on its phone.
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super-admin
        {--email= : Defaults to SUPER_ADMIN_EMAIL}
        {--password= : Defaults to SUPER_ADMIN_PASSWORD}
        {--phone= : Defaults to SUPER_ADMIN_PHONE}
        {--name= : Defaults to SUPER_ADMIN_NAME}';

    protected $description = 'Create or update the default super admin (email + password, phone)';

    public function handle(Hasher $hasher): int
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: config('yekkola.super_admin.email'))));
        $password = (string) ($this->option('password') ?: config('yekkola.super_admin.password'));
        $phone = (string) ($this->option('phone') ?: config('yekkola.super_admin.phone'));
        $name = (string) ($this->option('name') ?: config('yekkola.super_admin.name', 'Super admin'));

        $validator = Validator::make(compact('email', 'password', 'phone'), [
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
            'phone' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        try {
            $e164 = PhoneNumber::fromString($phone)->e164;
        } catch (Throwable) {
            $this->error('SUPER_ADMIN_PHONE is not a valid phone number.');

            return self::FAILURE;
        }

        $user = User::query()->where('phone_e164', $e164)->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$email])->first()
            ?? new User(['phone_e164' => $e164]);
        $user->forceFill([
            'email' => $email,
            'name' => $user->name ?? $name,
            'password' => $hasher->make($password),
            'password_changed_at' => now(),
            'must_change_password' => true,
        ])->save();
        $user->assignRole(Role::SuperAdmin->value, Role::Student->value);

        $this->info("Super admin ready: {$email} (must choose a new password at first sign-in).");

        return self::SUCCESS;
    }
}
