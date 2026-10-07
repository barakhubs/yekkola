<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\DataExportStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Jobs\BuildDataExport;
use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Queues a personal-data export (PRD-01 FR-12): one in progress at a time, one per day.
 */
final class RequestDataExport
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly LockProvider $locks,
    ) {}

    public function handle(User $user): DataExport
    {
        $lock = $this->locks->lock('data-export:'.$user->id, 10);
        $lock->block(5);

        try {
            $latest = DataExport::query()->where('user_id', $user->id)->latest()->first();

            if ($latest?->status === DataExportStatus::Pending) {
                throw IdentityException::exportInProgress();
            }

            if ($latest !== null && $latest->status === DataExportStatus::Ready && $latest->created_at->gt(now()->subDay())) {
                throw IdentityException::exportRateLimited((int) now()->diffInSeconds($latest->created_at->addDay()));
            }

            $export = DataExport::query()->create(['user_id' => $user->id, 'status' => DataExportStatus::Pending]);
        } finally {
            $lock->release();
        }

        $this->bus->dispatch(new BuildDataExport($export->id));

        return $export;
    }
}
