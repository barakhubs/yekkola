<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/** Brand images staff can replace (PRD-09 FR-15). No SVG: an uploaded SVG can carry scripts. */
enum BrandAsset: string
{
    /** Site header, sign-in pages, back office. */
    case Logo = 'logo';
    /** Site footer (often a light version for a dark band). */
    case FooterLogo = 'footer_logo';
    /** Browser tab and home-screen icon: a square PNG. */
    case Favicon = 'favicon';
    /** Default picture when a page is shared (WhatsApp, Facebook…). */
    case ShareImage = 'share_image';

    public function property(): string
    {
        return $this->value.'_path';
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Logo, self::FooterLogo => ['required', 'image', 'mimetypes:image/png,image/webp,image/jpeg', 'dimensions:min_height=32,max_width=2000,max_height=1000', 'max:1024'],
            self::Favicon => ['required', 'image', 'mimetypes:image/png', 'dimensions:ratio=1,min_width=48,max_width=1024', 'max:512'],
            self::ShareImage => ['required', 'image', 'mimetypes:image/png,image/jpeg,image/webp', 'dimensions:min_width=1200,min_height=630', 'max:5120'],
        };
    }
}
