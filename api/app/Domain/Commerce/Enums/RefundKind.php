<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum RefundKind: string
{
    /** A course a student asked to be refunded (PRD-05 FR-16). */
    case Item = 'item';
    /** Money collected but never applied to an order (amount mismatch, duplicate payment, already owned). */
    case Unapplied = 'unapplied';
}
