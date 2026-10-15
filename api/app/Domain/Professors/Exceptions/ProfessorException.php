<?php

declare(strict_types=1);

namespace App\Domain\Professors\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Professor onboarding errors (PRD-02). Each has a stable `code` rendered by the API error envelope.
 */
final class ProfessorException extends DomainException
{
    public static function alreadyProfessor(): self
    {
        return new self('professor.already_professor', 409);
    }

    public static function reapplyCooldown(int $retryAfter): self
    {
        return new self('application.reapply_cooldown', 409, ['retry_after' => $retryAfter]);
    }

    public static function applicationNotFound(): self
    {
        return new self('application.not_found', 404);
    }

    public static function applicationLocked(): self
    {
        return new self('application.locked', 409);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function applicationIncomplete(array $missing): self
    {
        return new self('application.incomplete', 422, ['missing' => $missing]);
    }

    public static function termsNotAccepted(): self
    {
        return new self('terms.not_accepted', 422);
    }

    public static function termsAcceptanceRequired(string $version): self
    {
        return new self('terms.acceptance_required', 403, ['version' => $version]);
    }

    public static function invalidTransition(): self
    {
        return new self('application.invalid_transition', 409);
    }

    public static function ownApplication(): self
    {
        return new self('application.own_application', 403);
    }

    public static function tooManyCredentials(int $max): self
    {
        return new self('application.too_many_credentials', 422, ['max' => $max]);
    }

    public static function slugAlreadyChanged(): self
    {
        return new self('professor.slug_already_changed', 409);
    }

    public static function kycAlreadyPending(): self
    {
        return new self('kyc.already_pending', 409);
    }

    public static function kycAlreadyVerified(): self
    {
        return new self('kyc.already_verified', 409);
    }

    public static function kycBackRequired(): self
    {
        return new self('kyc.back_required', 422);
    }

    public static function kycNotFound(): self
    {
        return new self('kyc.not_found', 404);
    }

    public static function kycNotPending(): self
    {
        return new self('kyc.not_pending', 409);
    }

    public static function notAProfessor(): self
    {
        return new self('professor.required', 403);
    }
}
