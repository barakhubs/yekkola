<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Actions\PayoutLifecycle;
use App\Domain\Finance\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one approved payout to the professor (PRD-08 FR-08), on the `payments` queue. */
final class ExecutePayout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $payoutId)
    {
        $this->onQueue('payments');
    }

    public function handle(PayoutLifecycle $payouts): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if ($payout !== null) {
            $payouts->execute($payout);
        }
    }
}
