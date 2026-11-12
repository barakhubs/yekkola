<?php

declare(strict_types=1);

namespace App\Http\Resources\Protection;

use App\Domain\Protection\OfflineGrant;
use App\Integrations\Video\Rendition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An offline download for this device (PRD-07 FR-07): the rendition's media playlist (signed segment URLs to
 * fetch the encrypted segments; the app rewrites the key URI to its own copy), the AES-128 key (base64, to be
 * stored only wrapped with a per-device key), and when the license expires. `media_id` changes when the
 * lesson's media is replaced: the app downloads again.
 *
 * @property OfflineGrant $resource
 */
final class OfflineLicenseResource extends JsonResource
{
    public function __construct(OfflineGrant $grant)
    {
        parent::__construct($grant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $grant = $this->resource;
        $license = $grant->license;

        return [
            'license_id' => $license->id,
            'lesson_uid' => $license->lesson_uid,
            'media_id' => $license->media_asset_id,
            'kind' => $grant->kind->value,
            'rendition' => self::rendition($grant->rendition),
            'renditions' => array_map(self::rendition(...), $grant->renditions),
            'playlist' => $grant->playlist,
            'segments_expire_at' => $grant->segmentsExpireAt->toIso8601String(),
            'key' => base64_encode($grant->key),
            'expires_at' => $license->expires_at->toIso8601String(),
            'watermark' => ['text' => $grant->watermark],
        ];
    }

    /**
     * @return array{name: string, size_bytes: int, width: int|null, height: int|null}
     */
    private static function rendition(Rendition $rendition): array
    {
        return [
            'name' => $rendition->name,
            'size_bytes' => $rendition->sizeBytes,
            'width' => $rendition->width,
            'height' => $rendition->height,
        ];
    }
}
