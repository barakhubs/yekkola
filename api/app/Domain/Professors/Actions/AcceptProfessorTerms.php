<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Models\TermsAcceptance;

/**
 * Records acceptance of the current professor agreement (PRD-02 FR-10). Idempotent per version.
 */
final class AcceptProfessorTerms
{
    public function handle(string $userId, ?string $ip): void
    {
        if (! TermsAcceptance::hasAcceptedCurrent($userId, LegalDocument::ProfessorAgreement)) {
            TermsAcceptance::record($userId, LegalDocument::ProfessorAgreement, $ip);
        }
    }
}
