<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use App\Domain\Platform\Enums\BrandAsset;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelSettings\Settings;

/**
 * Brand images staff upload in the back office (PRD-09 FR-15): paths on the public disk, null for the built-in
 * default. Changing one is audited like any setting.
 */
final class BrandingSettings extends Settings
{
    public ?string $logo_path;

    public ?string $footer_logo_path;

    public ?string $favicon_path;

    public ?string $share_image_path;

    public static function group(): string
    {
        return 'branding';
    }

    public function path(BrandAsset $asset): ?string
    {
        return $this->{$asset->property()};
    }

    /**
     * @return array{logo_url: string|null, footer_logo_url: string|null, favicon_url: string|null, share_image_url: string|null}
     */
    public function urls(): array
    {
        $disk = Storage::disk((string) config('yekkola.public_disk'));
        $url = fn (?string $path): ?string => $path === null ? null : $disk->url($path);

        return [
            'logo_url' => $url($this->logo_path),
            'footer_logo_url' => $url($this->footer_logo_path),
            'favicon_url' => $url($this->favicon_path),
            'share_image_url' => $url($this->share_image_path),
        ];
    }
}
