<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Launch category tree (provisional, docs/catalogue-categories.md). Idempotent by slug; admins edit it
 * afterwards in the back office, so re-running never overwrites names they changed.
 */
final class CategorySeeder extends Seeder
{
    /** slug => [fr, en, parent slug] */
    public const CATEGORIES = [
        'exetat' => ['Examen d’État (Exetat)', 'State Exam (Exetat)', null],
        'exetat-sciences' => ['Sciences (Maths, Physique, Chimie, Biologie)', 'Sciences', 'exetat'],
        'exetat-lettres' => ['Lettres & Langues', 'Humanities & Languages', 'exetat'],
        'exetat-commerciale' => ['Section commerciale & gestion', 'Commerce & Management stream', 'exetat'],
        'secondaire' => ['Secondaire (cours par classe)', 'Secondary school (by grade)', null],
        'mathematiques' => ['Mathématiques', 'Mathematics', null],
        'sciences' => ['Sciences (Physique, Chimie, Biologie)', 'Sciences', null],
        'francais' => ['Français', 'French', null],
        'anglais' => ['Anglais', 'English', null],
        'langues-nationales' => ['Langues nationales (Lingala, Swahili, Kikongo, Tshiluba)', 'National languages', null],
        'universite' => ['Université & préparation aux concours', 'University & entrance exams', null],
        'droit' => ['Droit', 'Law', null],
        'medecine-sante' => ['Médecine & santé', 'Medicine & health', null],
        'economie' => ['Économie', 'Economics', null],
        'informatique' => ['Informatique & programmation', 'IT & programming', null],
        'bureautique' => ['Bureautique (Word, Excel…)', 'Office skills', null],
        'entrepreneuriat' => ['Entrepreneuriat & business', 'Entrepreneurship & business', null],
        'comptabilite-finance' => ['Comptabilité & finance', 'Accounting & finance', null],
        'marketing-digital' => ['Marketing digital', 'Digital marketing', null],
        'agriculture' => ['Agriculture & agro-business', 'Agriculture & agribusiness', null],
        'metiers-techniques' => ['Métiers techniques (électricité, mécanique, BTP)', 'Technical trades', null],
        'sante-communautaire' => ['Santé communautaire', 'Community health', null],
        'developpement-personnel' => ['Développement personnel', 'Personal development', null],
    ];

    public function run(): void
    {
        $position = 0;

        foreach (self::CATEGORIES as $slug => [$fr, $en, $parentSlug]) {
            $parentId = $parentSlug === null ? null : Category::query()->where('slug', $parentSlug)->value('id');

            Category::query()->firstOrCreate(['slug' => $slug], [
                'name' => ['fr' => $fr, 'en' => $en],
                'parent_id' => $parentId,
                'position' => $position++,
            ]);
        }
    }
}
