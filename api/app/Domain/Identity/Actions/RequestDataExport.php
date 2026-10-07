<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\DataExportStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Jobs\BuildDataExport;
use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Queues a personal-data export (PRD-01 FR-12). One export may be in progress at a time.
 */
final class RequestDataExport
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function handle(User $user): DataExport
    {
        $pending = DataExport::query()
            ->where('user_id', $user->id)
            ->where('status', DataExportStatus::Pending)
            ->exists();

        if ($pending) {
            throw IdentityException::exportInProgress();
        }

        $export = DataExport::query()->create(['user_id' => $user->id, 'status' => DataExportStatus::Pending]);

        $this->bus->dispatch(new BuildDataExport($export->id));

        return $export;
    }
}
