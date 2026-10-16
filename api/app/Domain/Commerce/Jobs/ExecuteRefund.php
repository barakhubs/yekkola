<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Actions\RefundLifecycle;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Pays out an approved refund to the original payer (PRD-05 FR-16), on the `payments` queue. */
final class ExecuteRefund implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $refundId)
    {
        $this->onQueue('payments');
    }

    public function handle(RefundLifecycle $refunds): void
    {
        $refund = Refund::query()->find($this->refundId);
        if ($refund !== null) {
            $refunds->execute($refund);
        }
    }
}
