<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\TermsAcceptance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Studio routes (alias `professor`, after `signed-in`): the user must be an approved professor and have
 * accepted the current professor agreement (PRD-02 FR-10). The terms routes themselves are exempt.
 * The profile is shared on the request as `professor_profile`.
 */
final class EnsureProfessor
{
    /** Routes reachable before re-accepting changed terms. */
    private const TERMS_EXEMPT = ['professor.terms.accept', 'professor.profile.show'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->professorProfile;

        if ($profile === null || ! $user->hasRole(Role::Professor->value)) {
            throw ProfessorException::notAProfessor();
        }

        $routeName = $request->route()?->getName();
        if (! in_array($routeName, self::TERMS_EXEMPT, true)
            && ! TermsAcceptance::hasAcceptedCurrent($user->id, LegalDocument::ProfessorAgreement)) {
            throw ProfessorException::termsAcceptanceRequired(LegalDocument::ProfessorAgreement->currentVersion());
        }

        $request->attributes->set('professor_profile', $profile);

        return $next($request);
    }
}
