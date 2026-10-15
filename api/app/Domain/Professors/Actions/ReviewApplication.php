<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Events\ApplicationDecided;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff decisions on professor applications (PRD-02 FR-04/05, PRD-09 FR-05): start review, approve,
 * reject, or request more information — each audited with the reviewer's note. Staff can't decide on
 * their own application (PRD-09 BR-02).
 */
final class ReviewApplication
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $events,
        private readonly ProfessorSlugs $slugs,
    ) {}

    public function startReview(ProfessorApplication $application, User $staff): ProfessorApplication
    {
        $this->guard($application, $staff, [ApplicationStatus::Submitted]);

        $application->forceFill([
            'status' => ApplicationStatus::UnderReview,
            'reviewer_id' => $staff->id,
            'review_started_at' => now(),
        ])->save();

        $this->recordAudit->handle('professor_application.review_started', $application, causer: $staff);

        return $application;
    }

    public function approve(ProfessorApplication $application, User $staff, ?string $note): ProfessorProfile
    {
        $this->guard($application, $staff, [ApplicationStatus::Submitted, ApplicationStatus::UnderReview]);

        $profile = $this->db->transaction(function () use ($application, $staff, $note): ProfessorProfile {
            $application->forceFill([
                'status' => ApplicationStatus::Approved,
                'reviewer_id' => $staff->id,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            $applicant = $application->user;
            $profile = ProfessorProfile::query()->create([
                'user_id' => $applicant->id,
                'application_id' => $application->id,
                'slug' => $this->slugs->unique((string) $application->full_name),
                'display_name' => (string) $application->full_name,
                'subjects' => $application->subjects,
                'province_id' => $application->province_id,
            ]);

            $applicant->assignRole(Role::Professor->value);

            $this->recordAudit->handle('professor_application.approved', $application, ['note' => $note], causer: $staff);

            return $profile;
        });

        $this->events->dispatch(new ApplicationDecided($application));

        return $profile;
    }

    public function reject(ProfessorApplication $application, User $staff, string $note): ProfessorApplication
    {
        $this->guard($application, $staff, [ApplicationStatus::Submitted, ApplicationStatus::UnderReview]);

        $application->forceFill([
            'status' => ApplicationStatus::Rejected,
            'reviewer_id' => $staff->id,
            'decision_note' => $note,
            'decided_at' => now(),
        ])->save();

        $this->recordAudit->handle('professor_application.rejected', $application, ['note' => $note], causer: $staff);
        $this->events->dispatch(new ApplicationDecided($application));

        return $application;
    }

    public function requestInfo(ProfessorApplication $application, User $staff, string $note): ProfessorApplication
    {
        $this->guard($application, $staff, [ApplicationStatus::Submitted, ApplicationStatus::UnderReview]);

        $application->forceFill([
            'status' => ApplicationStatus::NeedsInfo,
            'reviewer_id' => $staff->id,
            'info_request_note' => $note,
            'info_requested_at' => now(),
        ])->save();

        $this->recordAudit->handle('professor_application.info_requested', $application, ['note' => $note], causer: $staff);
        $this->events->dispatch(new ApplicationDecided($application));

        return $application;
    }

    /**
     * @param  list<ApplicationStatus>  $allowed
     */
    private function guard(ProfessorApplication $application, User $staff, array $allowed): void
    {
        if ($application->user_id === $staff->id) {
            throw ProfessorException::ownApplication();
        }

        if (! in_array($application->status, $allowed, true)) {
            throw ProfessorException::invalidTransition();
        }
    }
}
