<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A student asked for a refund (staff queue, PRD-09 FR-12). */
final readonly class RefundRequested implements ShouldDispatchAfterCommit
{
    public function __construct(public string $refundId) {}
}
