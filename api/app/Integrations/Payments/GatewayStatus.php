<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

enum GatewayStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Succeeded, then reversed by the operator (chargeback-like). */
    case Reversed = 'reversed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
