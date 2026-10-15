<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ModerationSettings;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Creates or autosaves the user's open application (PRD-02 FR-01/02). Only drafts and "needs info"
 * applications are editable; a rejected applicant may start again after the cooldown (FR-06).
 */
final class SaveApplicationDraft
{
    public function __construct(
        private readonly ModerationSettings $settings,
        private readonly LockProvider $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated application fields (all optional while drafting).
     */
    public function handle(User $user, array $data): ProfessorApplication
    {
        $lock = $this->locks->lock('professor-application:'.$user->id, 10);
        $lock->block(5);

        try {
            $application = $this->openApplication($user) ?? $this->startNew($user);

            if (! $application->status->isEditable()) {
                throw ProfessorException::applicationLocked();
            }

            $application->fill($data)->save();

            return $application;
        } finally {
            $lock->release();
        }
    }

    public function openApplication(User $user): ?ProfessorApplication
    {
        return ProfessorApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $s->isOpen()))
            ->first();
    }

    private function startNew(User $user): ProfessorApplication
    {
        if (ProfessorProfile::query()->where('user_id', $user->id)->exists()) {
            throw ProfessorException::alreadyProfessor();
        }

        $lastRejected = ProfessorApplication::query()
            ->where('user_id', $user->id)
            ->where('status', ApplicationStatus::Rejected)
            ->latest('decided_at')
            ->first();

        $cooldownEnds = $lastRejected?->decided_at?->copy()->addDays($this->settings->application_reapply_cooldown_days);
        if ($cooldownEnds !== null && $cooldownEnds->isFuture()) {
            throw ProfessorException::reapplyCooldown((int) now()->diffInSeconds($cooldownEnds));
        }

        return ProfessorApplication::query()->create([
            'user_id' => $user->id,
            'status' => ApplicationStatus::Draft,
            'full_name' => $user->name,
            'province_id' => $user->province_id,
        ]);
    }
}
