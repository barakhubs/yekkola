<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Catalog\Jobs\RevalidateWebPages;
use App\Domain\Platform\Enums\BrandAsset;
use App\Domain\Platform\Settings\BrandingSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Replaces or removes a brand image (PRD-09 FR-15). The file goes to the public disk under a new name each time
 * (browsers and CDNs never serve a stale one); the setting change is audited by AuditSettingsChange; the old file
 * is deleted once the change is saved, and the public pages are refreshed.
 */
final class ManageBranding
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly BrandingSettings $settings,
    ) {}

    public function replace(BrandAsset $asset, UploadedFile $file): BrandingSettings
    {
        $disk = Storage::disk((string) config('yekkola.public_disk'));
        $path = $disk->putFileAs('branding', $file, $asset->value.'-'.Str::lower((string) Str::ulid()).'.'.$file->extension(), 'public');
        if ($path === false) {
            throw new RuntimeException('Could not store the brand image.');
        }

        try {
            return $this->change($asset, $path);
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    public function remove(BrandAsset $asset): BrandingSettings
    {
        return $this->change($asset, null);
    }

    private function change(BrandAsset $asset, ?string $path): BrandingSettings
    {
        $old = $this->settings->path($asset);
        if ($old === $path) {
            return $this->settings;
        }

        // The audit entry is written while saving: one transaction keeps them together.
        $this->db->transaction(function () use ($asset, $path): void {
            $this->settings->{$asset->property()} = $path;
            $this->settings->save();
        });

        if ($old !== null) {
            Storage::disk((string) config('yekkola.public_disk'))->delete($old);
        }
        RevalidateWebPages::dispatch(['branding']);

        return $this->settings;
    }
}
