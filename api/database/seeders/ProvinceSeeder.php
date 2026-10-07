<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Platform\Models\Province;
use Illuminate\Database\Seeder;

/**
 * The 26 provinces of the DRC (2015 constitution), keyed by ISO 3166-2:CD code. Idempotent.
 */
final class ProvinceSeeder extends Seeder
{
    public const PROVINCES = [
        'CD-BC' => 'Kongo-Central',
        'CD-BU' => 'Bas-Uele',
        'CD-EQ' => 'Équateur',
        'CD-HK' => 'Haut-Katanga',
        'CD-HL' => 'Haut-Lomami',
        'CD-HU' => 'Haut-Uele',
        'CD-IT' => 'Ituri',
        'CD-KC' => 'Kasaï-Central',
        'CD-KE' => 'Kasaï-Oriental',
        'CD-KG' => 'Kwango',
        'CD-KL' => 'Kwilu',
        'CD-KN' => 'Kinshasa',
        'CD-KS' => 'Kasaï',
        'CD-LO' => 'Lomami',
        'CD-LU' => 'Lualaba',
        'CD-MA' => 'Maniema',
        'CD-MN' => 'Mai-Ndombe',
        'CD-MO' => 'Mongala',
        'CD-NK' => 'Nord-Kivu',
        'CD-NU' => 'Nord-Ubangi',
        'CD-SA' => 'Sankuru',
        'CD-SK' => 'Sud-Kivu',
        'CD-SU' => 'Sud-Ubangi',
        'CD-TA' => 'Tanganyika',
        'CD-TO' => 'Tshopo',
        'CD-TU' => 'Tshuapa',
    ];

    public function run(): void
    {
        foreach (self::PROVINCES as $code => $name) {
            Province::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
