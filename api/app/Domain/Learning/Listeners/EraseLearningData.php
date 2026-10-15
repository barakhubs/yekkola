<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Learning\Actions\Wishlist;

/**
 * Erases a deleted account's learning data (PRD-01 FR-11). For now the wishlist; enrollments, progress,
 * and certificates are handled when they arrive (they are records the platform must keep, anonymised).
 */
final class EraseLearningData
{
    public function __construct(private readonly Wishlist $wishlist) {}

    public function handle(UserAnonymizing $event): void
    {
        $this->wishlist->clear($event->user);
    }
}
