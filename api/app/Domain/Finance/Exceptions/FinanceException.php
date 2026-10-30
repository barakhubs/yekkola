<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class FinanceException extends DomainException
{
    public static function batchOpen(string $batchId): self
    {
        return new self('payout.batch_open', 409, ['batch_id' => $batchId]);
    }

    public static function nothingToPay(): self
    {
        return new self('payout.nothing_to_pay', 422);
    }

    public static function batchNotDraft(): self
    {
        return new self('payout.batch_not_draft', 409);
    }

    public static function payoutNotPending(): self
    {
        return new self('payout.not_pending', 409);
    }

    public static function ownPayout(): self
    {
        return new self('payout.own_payout', 403);
    }
}
