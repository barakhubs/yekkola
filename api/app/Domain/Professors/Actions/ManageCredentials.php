<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorApplication;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Credential files attached to an application (diplomas, certificates — PRD-02 FR-01), private disk.
 */
final class ManageCredentials
{
    public function add(ProfessorApplication $application, UploadedFile $file): Media
    {
        if (! $application->status->isEditable()) {
            throw ProfessorException::applicationLocked();
        }

        if ($application->getMedia(ProfessorApplication::CREDENTIALS)->count() >= ProfessorApplication::MAX_CREDENTIAL_FILES) {
            throw ProfessorException::tooManyCredentials(ProfessorApplication::MAX_CREDENTIAL_FILES);
        }

        return $application->addMedia($file)
            ->usingFileName(uniqid('credential-', true).'.'.$file->extension())
            ->toMediaCollection(ProfessorApplication::CREDENTIALS);
    }

    public function remove(ProfessorApplication $application, string $mediaUuid): void
    {
        if (! $application->status->isEditable()) {
            throw ProfessorException::applicationLocked();
        }

        $media = $application->getMedia(ProfessorApplication::CREDENTIALS)->firstWhere('uuid', $mediaUuid)
            ?? throw ProfessorException::applicationNotFound();

        $media->delete();
    }
}
