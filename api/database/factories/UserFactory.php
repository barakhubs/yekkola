<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // DRC mobile numbers: +243 followed by 9 digits (8x/9x prefixes).
            'phone_e164' => '+243'.fake()->unique()->numerify(fake()->randomElement(['81', '82', '84', '85', '89', '97', '99']).'#######'),
            'phone_verified_at' => now(),
            'name' => fake()->name(),
            'email' => null,
            'locale' => 'fr',
            'status' => UserStatus::Active,
        ];
    }

    public function withEmail(): static
    {
        return $this->state(fn () => [
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Banned]);
    }
}
