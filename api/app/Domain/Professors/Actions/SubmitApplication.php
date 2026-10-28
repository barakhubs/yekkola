<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Events\ApplicationSubmitted;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\TermsAcceptance;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Submits (or resubmits after "needs info") the applicant's open application (PRD-02 FR-01–03).
 * Requires a complete application and acceptance of the current professor agreement (FR-10).
 */
final class SubmitApplication
{
    /** Fields that must be filled before submitting. */
    private const REQUIRED = ['full_name', 'subjects', 'levels', 'years_experience', 'motivation'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SaveApplicationDraft $drafts,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Required fields still empty, in form order (the applicant sees what blocks submission).
     *
     * @return list<string>
     */
    public function missing(ProfessorApplication $application): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $field) => blank($application->{$field})));
    }

    public function handle(User $user, bool $acceptsTerms, ?string $ip): ProfessorApplication
    {
        $application = $this->drafts->openApplication($user) ?? throw ProfessorException::applicationNotFound();

        if (! $application->status->isEditable()) {
            throw ProfessorException::applicationLocked();
        }

        $missing = $this->missing($application);
        if ($missing !== []) {
            throw ProfessorException::applicationIncomplete($missing);
        }

        if (! $acceptsTerms) {
            throw ProfessorException::termsNotAccepted();
        }

        $this->db->transaction(function () use ($application, $user, $ip): void {
            TermsAcceptance::record($user->id, LegalDocument::ProfessorAgreement, $ip);

            $application->forceFill([
                'status' => ApplicationStatus::Submitted,
                'terms_version' => LegalDocument::ProfessorAgreement->currentVersion(),
                'submitted_at' => now(),
            ])->save();
        });

        $this->events->dispatch(new ApplicationSubmitted($application));

        return $application;
    }
}
