<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Learning\Actions\IssueCertificate;
use App\Domain\Learning\Events\ProgressRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Applies the completion rule whenever progress changes (PRD-06 FR-10). Queued: issuing is never on the sync path. */
final class IssueCertificateOnProgress implements ShouldQueue
{
    public function __construct(private readonly IssueCertificate $issue) {}

    public function handle(ProgressRecorded $event): void
    {
        $this->issue->handle($event->enrollmentId);
    }
}
