<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

/** Versioned legal texts users accept (current versions in config/yekkola.php → terms). */
enum LegalDocument: string
{
    case ProfessorAgreement = 'professor_agreement';

    public function currentVersion(): string
    {
        return (string) config('yekkola.terms.'.$this->value);
    }
}
