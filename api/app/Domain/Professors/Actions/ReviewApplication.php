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
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff decisions on professor applications (PRD-02 FR-04/05, PRD-09 FR-05): start review, approve,
 * reject, or request more information — each audited with the reviewer's note. Staff can't decide on
 * their own application (PRD-09 BR-02).
 *
 * The transitions of one application's state machine live together. Each one re-reads the application
 * under a row lock, so two reviewers acting at once can't both succeed.
 */
final class ReviewApplication
{
    private const DECIDABLE = [ApplicationStatus::Submitted, ApplicationStatus::UnderReview];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $events,
        private readonly ProfessorSlugs $slugs,
    ) {}

    /** Not their own application, and still waiting to be picked up. */
    public function canStartReview(ProfessorApplication $application, User $staff): bool
    {
        return $this->allows($application, $staff, [ApplicationStatus::Submitted]);
    }

    /** Not their own application, and submitted or under review (approve, reject, ask for more information). */
    public function canDecide(ProfessorApplication $application, User $staff): bool
    {
        return $this->allows($application, $staff, self::DECIDABLE);
    }

    public function startReview(ProfessorApplication $application, User $staff): ProfessorApplication
    {
        return $this->transition($application, $staff, [ApplicationStatus::Submitted], function (ProfessorApplication $locked) use ($staff): ProfessorApplication {
            $locked->forceFill([
                'status' => ApplicationStatus::UnderReview,
                'reviewer_id' => $staff->id,
                'review_started_at' => now(),
            ])->save();

            $this->recordAudit->handle('professor_application.review_started', $locked, causer: $staff);

            return $locked;
        }, notify: false);
    }

    public function approve(ProfessorApplication $application, User $staff, ?string $note): ProfessorProfile
    {
        $profile = null;

        $this->transition($application, $staff, self::DECIDABLE, function (ProfessorApplication $locked) use ($staff, $note, &$profile): ProfessorApplication {
            $locked->forceFill([
                'status' => ApplicationStatus::Approved,
                'reviewer_id' => $staff->id,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            $applicant = $locked->user;
            $profile = ProfessorProfile::query()->create([
                'user_id' => $applicant->id,
                'application_id' => $locked->id,
                'slug' => $this->slugs->unique((string) $locked->full_name),
                'display_name' => (string) $locked->full_name,
                'subjects' => $locked->subjects,
                'province_id' => $locked->province_id,
            ]);

            $applicant->assignRole(Role::Professor->value);

            $this->recordAudit->handle('professor_application.approved', $locked, ['note' => $note], causer: $staff);

            return $locked;
        });

        /** @var ProfessorProfile $profile */
        return $profile;
    }

    public function reject(ProfessorApplication $application, User $staff, string $note): ProfessorApplication
    {
        return $this->transition($application, $staff, self::DECIDABLE, function (ProfessorApplication $locked) use ($staff, $note): ProfessorApplication {
            $locked->forceFill([
                'status' => ApplicationStatus::Rejected,
                'reviewer_id' => $staff->id,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            $this->recordAudit->handle('professor_application.rejected', $locked, ['note' => $note], causer: $staff);

            return $locked;
        });
    }

    public function requestInfo(ProfessorApplication $application, User $staff, string $note): ProfessorApplication
    {
        return $this->transition($application, $staff, self::DECIDABLE, function (ProfessorApplication $locked) use ($staff, $note): ProfessorApplication {
            $locked->forceFill([
                'status' => ApplicationStatus::NeedsInfo,
                'reviewer_id' => $staff->id,
                'info_request_note' => $note,
                'info_requested_at' => now(),
            ])->save();

            $this->recordAudit->handle('professor_application.info_requested', $locked, ['note' => $note], causer: $staff);

            return $locked;
        });
    }

    /**
     * @param  list<ApplicationStatus>  $allowed
     */
    private function allows(ProfessorApplication $application, User $staff, array $allowed): bool
    {
        return $application->user_id !== $staff->id && in_array($application->status, $allowed, true);
    }

    /**
     * @param  list<ApplicationStatus>  $allowed
     * @param  Closure(ProfessorApplication): ProfessorApplication  $apply
     */
    private function transition(ProfessorApplication $application, User $staff, array $allowed, Closure $apply, bool $notify = true): ProfessorApplication
    {
        if ($application->user_id === $staff->id) {
            throw ProfessorException::ownApplication();
        }

        /** @var ProfessorApplication $result */
        $result = $this->db->transaction(function () use ($application, $allowed, $apply): ProfessorApplication {
            $locked = ProfessorApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $allowed, true)) {
                throw ProfessorException::invalidTransition();
            }

            return $apply($locked);
        });

        if ($notify) {
            $this->events->dispatch(new ApplicationDecided($result));
        }

        return $result;
    }
}
