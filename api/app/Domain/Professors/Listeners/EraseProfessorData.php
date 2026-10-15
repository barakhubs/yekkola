<?php

declare(strict_types=1);

namespace App\Domain\Professors\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Professors\Enums\ProfessorStatus;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Erases a deleted account's professor data (PRD-01 FR-11): profile text, payout details, application
 * answers, and every file — avatar, credentials, ID images. The profile row stays (hidden, suspended)
 * because earnings and courses reference it. Files are removed only after the transaction commits.
 */
final class EraseProfessorData
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(UserAnonymizing $event): void
    {
        $userId = $event->user->id;
        $withFiles = [];

        foreach (ProfessorApplication::query()->where('user_id', $userId)->get() as $application) {
            $application->forceFill([
                'full_name' => null,
                'institutions' => null,
                'credentials' => null,
                'sample_url' => null,
                'motivation' => null,
                'province_id' => null,
            ])->save();
            $withFiles[] = $application;
        }

        $profile = ProfessorProfile::query()->where('user_id', $userId)->first();
        if ($profile !== null) {
            $profile->forceFill([
                'slug' => 'deleted-'.Str::lower((string) Str::ulid()),
                'display_name' => '—',
                'headline' => null,
                'bio' => null,
                'links' => [],
                'province_id' => null,
                'status' => ProfessorStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => 'account_deleted',
                'payout_rail' => null,
                'payout_msisdn' => null,
                'payout_name' => null,
            ])->save();
            $withFiles[] = $profile;
            array_push($withFiles, ...$profile->kycSubmissions()->get()->all());
        }

        $this->db->connection()->afterCommit(function () use ($withFiles): void {
            foreach ($withFiles as $model) {
                foreach ($model->media()->get() as $media) {
                    $media->delete();
                }
            }
        });
    }
}
