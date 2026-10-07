<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * Status reported by the gateway. None of these is guaranteed permanent: a "failed" payment can
 * succeed late, and a "succeeded" one can be reversed — keep reconciling (PRD-05 BR-04, PRD-08 §7).
 */
enum GatewayStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Succeeded, then reversed by the operator (chargeback-like). */
    case Reversed = 'reversed';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Money has moved (and, for now, stays moved). */
    public function isSettled(): bool
    {
        return $this === self::Succeeded;
    }
}
