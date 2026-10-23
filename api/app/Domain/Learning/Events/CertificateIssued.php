<?php

declare(strict_types=1);

namespace App\Domain\Learning\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A student earned a certificate (notifications, analytics `certificate_issued`). */
final readonly class CertificateIssued implements ShouldDispatchAfterCommit
{
    public function __construct(public string $certificateId) {}
}
