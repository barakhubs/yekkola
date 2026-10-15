<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The professor edits their public profile (PRD-02 FR-07). The slug can be changed once.
 */
final class UpdateProfessorProfile
{
    public function __construct(private readonly ProfessorSlugs $slugs) {}

    /**
     * @param  array<string, mixed>  $data  Validated: display_name, headline, bio, subjects, links, province_id, slug.
     */
    public function handle(ProfessorProfile $profile, array $data): ProfessorProfile
    {
        if (array_key_exists('slug', $data) && $data['slug'] !== $profile->slug) {
            if (! $profile->canChangeSlug()) {
                throw ProfessorException::slugAlreadyChanged();
            }

            if ($this->slugs->taken((string) $data['slug'], $profile->id)) {
                throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
            }

            $profile->slug_changed_at = now();
        }

        $profile->fill($data)->save();

        return $profile;
    }

    public function setAvatar(ProfessorProfile $profile, UploadedFile $file): ProfessorProfile
    {
        $profile->addMedia($file)
            ->usingFileName('avatar.'.$file->extension())
            ->toMediaCollection(ProfessorProfile::AVATAR);

        return $profile->refresh();
    }
}
