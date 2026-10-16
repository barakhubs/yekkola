<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Staff approved or rejected a refund (student notification, PRD-10). */
final readonly class RefundDecided implements ShouldDispatchAfterCommit
{
    public function __construct(public string $refundId) {}
}
