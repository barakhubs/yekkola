<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Platform\Settings\BrandingSettings;
use App\Http\Controllers\Controller;

/** The brand images to show (PRD-09 FR-15); null means the app's built-in default. */
final class BrandingController extends Controller
{
    /**
     * @return array{data: array{logo_url: string|null, footer_logo_url: string|null, favicon_url: string|null, share_image_url: string|null}}
     */
    public function show(BrandingSettings $branding): array
    {
        return ['data' => $branding->urls()];
    }
}
