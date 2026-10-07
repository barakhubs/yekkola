<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Erases personal data after the deletion grace period (PRD-01 FR-11). The row stays (soft-deleted) under
 * its id so financial records (orders, ledger) remain consistent; the phone number is freed for reuse.
 * Export files are deleted only after the transaction commits.
 */
final class AnonymizeUser
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SignOutEverywhere $signOutEverywhere,
        private readonly RevokeDevice $revokeDevice,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(User $user): void
    {
        $phone = $user->phone_e164;

        /** @var list<string> $files */
        $files = $this->db->transaction(function () use ($user, $phone): array {
            foreach ($user->devices()->active()->get() as $device) {
                $this->revokeDevice->handle($device);
            }

            $this->signOutEverywhere->handle($user);

            foreach ($user->devices()->get() as $device) {
                $device->forceFill(['name' => null, 'push_token' => null, 'install_id' => (string) Str::ulid()])->save();
            }

            $exports = DataExport::query()->where('user_id', $user->id)->get();
            $files = $exports->pluck('path')->filter()->values()->all();
            DataExport::query()->where('user_id', $user->id)->delete();

            OtpChallenge::query()->where('user_id', $user->id)->orWhere('phone_e164', $phone)->delete();
            $this->db->table('sessions')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'phone_e164' => 'deleted:'.Str::ulid(),
                'phone_verified_at' => null,
                'name' => null,
                'email' => null,
                'email_verified_at' => null,
                'city' => null,
                'province_id' => null,
                'deletion_requested_at' => null,
            ])->save();

            $user->syncRoles([]);
            $user->delete();

            $this->recordAudit->handle('user.anonymized', $user);

            return $files;
        });

        foreach ($files as $path) {
            DataExport::disk()->delete($path);
        }
    }
}
