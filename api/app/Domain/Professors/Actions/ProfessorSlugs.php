<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Support\Str;

/**
 * Public URL slugs for professor pages: ASCII, lower-case, unique ("marie-kabila", "marie-kabila-2").
 */
final class ProfessorSlugs
{
    public const MAX_LENGTH = 60;

    public function unique(string $name, ?string $ignoreProfileId = null): string
    {
        $base = Str::limit(Str::slug($name), self::MAX_LENGTH, '');
        $base = $base !== '' ? rtrim($base, '-') : 'professeur';

        $slug = $base;
        $n = 2;
        while ($this->taken($slug, $ignoreProfileId)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function taken(string $slug, ?string $ignoreProfileId = null): bool
    {
        return ProfessorProfile::query()
            ->where('slug', $slug)
            ->when($ignoreProfileId !== null, fn ($q) => $q->whereKeyNot($ignoreProfileId))
            ->exists();
    }
}
