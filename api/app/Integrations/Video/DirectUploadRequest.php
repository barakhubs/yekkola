<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;

final readonly class DirectUploadRequest
{
    public function __construct(
        public MediaKind $kind,
        /** Our MediaAsset id, echoed back in webhooks so we can match the upload. */
        public string $passthrough,
        /** Origin allowed to upload from the browser (the studio's URL). */
        public string $corsOrigin,
        /** Burned-in watermark (logo + professor name) image URL; video only. */
        public ?string $watermarkUrl = null,
    ) {}
}
