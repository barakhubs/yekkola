<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** The operator reversed a payment after success: access was revoked and the sale reversed (PRD-05 BR-04). */
final readonly class PaymentReversed implements ShouldDispatchAfterCommit
{
    public function __construct(public string $orderId, public string $paymentId) {}
}
