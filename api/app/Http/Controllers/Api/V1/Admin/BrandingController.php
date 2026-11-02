<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Actions\ManageBranding;
use App\Domain\Platform\Enums\BrandAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandAssetRequest;
use Illuminate\Http\UploadedFile;

/**
 * Brand images (PRD-09 FR-15): upload a logo, footer logo, favicon or default share image, or go back to the
 * built-in one. Audited; the public pages refresh.
 */
final class BrandingController extends Controller
{
    /**
     * Logo and footer logo: PNG, WebP or JPEG, at least 32 px high, up to 1 MB. Favicon: square PNG, 48–1024 px,
     * up to 512 KB. Share image: at least 1200×630, up to 5 MB.
     *
     * @return array{data: array{logo_url: string|null, footer_logo_url: string|null, favicon_url: string|null, share_image_url: string|null}}
     */
    public function update(BrandAssetRequest $request, BrandAsset $asset, ManageBranding $branding): array
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return ['data' => $branding->replace($asset, $file)->urls()];
    }

    /**
     * @return array{data: array{logo_url: string|null, footer_logo_url: string|null, favicon_url: string|null, share_image_url: string|null}}
     */
    public function destroy(BrandAsset $asset, ManageBranding $branding): array
    {
        return ['data' => $branding->remove($asset)->urls()];
    }
}
