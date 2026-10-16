<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** An order was paid and its enrollments granted (receipts and notifications listen, PRD-10). */
final readonly class OrderPaid implements ShouldDispatchAfterCommit
{
    public function __construct(public string $orderId) {}
}
