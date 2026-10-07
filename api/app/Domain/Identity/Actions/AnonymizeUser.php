<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Contracts\Filesystem\Factory as Storage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Erases personal data after the deletion grace period (PRD-01 FR-11). The row stays (soft-deleted) under
 * its id so financial records (orders, ledger) remain consistent; the phone number is freed for reuse.
 */
final class AnonymizeUser
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SignOutEverywhere $signOutEverywhere,
        private readonly RevokeDevice $revokeDevice,
        private readonly Storage $storage,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            foreach ($user->devices()->active()->get() as $device) {
                $this->revokeDevice->handle($device);
            }

            $this->signOutEverywhere->handle($user);

            foreach (DataExport::query()->where('user_id', $user->id)->get() as $export) {
                if ($export->path !== null) {
                    $this->storage->disk(DataExport::DISK)->delete($export->path);
                }
                $export->delete();
            }

            foreach ($user->devices()->get() as $device) {
                $device->forceFill(['name' => null, 'push_token' => null, 'install_id' => (string) Str::ulid()])->save();
            }

            $user->forceFill([
                'phone_e164' => 'deleted:'.Str::ulid(),
                'phone_verified_at' => null,
                'name' => null,
                'email' => null,
                'email_verified_at' => null,
                'city' => null,
                'province_id' => null,
            ])->save();

            $user->syncRoles([]);
            $user->delete();

            $this->recordAudit->handle('user.anonymized', $user);
        });
    }
}
