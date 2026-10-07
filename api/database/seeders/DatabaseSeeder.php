<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Reference data — safe to run in every environment.
        $this->call([
            ProvinceSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        // Local/staging demo data only.
        if (app()->environment(['local', 'staging', 'testing'])) {
            User::factory()->create([
                'phone_e164' => '+243810000001',
                'name' => 'Admin Démo',
            ])->assignRole(Role::SuperAdmin->value, Role::Student->value);
        }
    }
}
