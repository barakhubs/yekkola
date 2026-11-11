<?php

declare(strict_types=1);

namespace App\Domain\Protection;

use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Protection\Models\OfflineLicense;
use App\Integrations\Video\Rendition;
use Carbon\CarbonImmutable;

/** What the app needs to download one lesson and play it offline (PRD-07 FR-07, FR-16). */
final readonly class OfflineGrant
{
    /**
     * @param  list<Rendition>  $renditions
     */
    public function __construct(
        public OfflineLicense $license,
        public MediaKind $kind,
        public Rendition $rendition,
        /** Every rendition with its size, for the quality choice (FR-08). */
        public array $renditions,
        /** The rendition's media playlist: signed segment URLs (until `segmentsExpireAt`) and our key URI. */
        public string $playlist,
        /** The asset's raw 16-byte AES-128 key. The app keeps it only wrapped with a per-device key. */
        public string $key,
        public CarbonImmutable $segmentsExpireAt,
        public string $watermark,
    ) {}
}
