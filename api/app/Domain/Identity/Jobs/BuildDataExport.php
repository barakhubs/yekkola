<?php

declare(strict_types=1);

namespace App\Domain\Identity\Jobs;

use App\Domain\Identity\Enums\DataExportStatus;
use App\Domain\Identity\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds the JSON export of a user's personal data (PRD-01 FR-12) on the private disk.
 * Later phases add their data here (enrollments, orders, certificates, reviews…).
 */
final class BuildDataExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $exportId) {}

    public function handle(): void
    {
        $export = DataExport::query()->with('user.province', 'user.devices')->findOrFail($this->exportId);
        $user = $export->user;

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'phone' => $user->phone_e164,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'province' => $user->province?->name,
                'city' => $user->city,
                'created_at' => $user->created_at->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'roles' => $user->getRoleNames()->all(),
            'devices' => $user->devices->map(fn ($d) => [
                'platform' => $d->platform->value,
                'name' => $d->name,
                'registered_at' => $d->registered_at->toIso8601String(),
                'last_active_at' => $d->last_active_at?->toIso8601String(),
                'revoked_at' => $d->revoked_at?->toIso8601String(),
            ])->all(),
        ];

        $path = "exports/{$user->id}/{$export->id}.json";
        DataExport::disk()->put($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $export->forceFill([
            'status' => DataExportStatus::Ready,
            'path' => $path,
            'ready_at' => now(),
            'expires_at' => now()->addDays((int) config('yekkola.auth.export_ttl_days')),
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => DataExportStatus::Failed]);
    }
}
