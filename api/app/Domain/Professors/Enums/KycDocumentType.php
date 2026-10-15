<?php

declare(strict_types=1);

namespace App\Domain\Professors\Enums;

enum KycDocumentType: string
{
    case NationalId = 'national_id';
    case VoterCard = 'voter_card';
    case Passport = 'passport';

    /** Passports have no back side. */
    public function hasBackSide(): bool
    {
        return $this !== self::Passport;
    }
}
