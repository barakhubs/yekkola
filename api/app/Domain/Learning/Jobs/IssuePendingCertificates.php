<?php

declare(strict_types=1);

namespace App\Domain\Learning\Jobs;

use App\Domain\Learning\Actions\IssueCertificate;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-applies the completion rule to a student's active enrollments (IssueCertificate is idempotent). */
final class IssuePendingCertificates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $userId) {}

    public function handle(IssueCertificate $issue): void
    {
        Enrollment::query()->active()->where('user_id', $this->userId)
            ->pluck('id')->each(fn (string $id) => $issue->handle($id));
    }
}
