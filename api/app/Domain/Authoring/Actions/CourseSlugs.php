<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Models\Course;
use Illuminate\Support\Str;

/**
 * Public URL slugs for courses, from the first title. Kept stable afterwards (SEO).
 */
final class CourseSlugs
{
    public function unique(string $title): string
    {
        $base = rtrim(Str::limit(Str::slug($title), 80, ''), '-');
        $base = $base !== '' ? $base : 'cours';

        $slug = $base;
        $n = 2;
        while (Course::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
