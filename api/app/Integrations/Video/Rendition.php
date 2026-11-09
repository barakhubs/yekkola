<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** One HLS rendition of an asset: `720p`, `480p`, `360p` (video) or `audio`. */
final readonly class Rendition
{
    public function __construct(
        public string $name,
        /** Peak bits per second, for the master playlist's BANDWIDTH. */
        public int $bandwidth,
        /** Total size of its segments (the download size shown to students). */
        public int $sizeBytes,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    /**
     * @param  array{name: string, bandwidth: int, size_bytes: int, width?: int|null, height?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], (int) $data['bandwidth'], (int) $data['size_bytes'], $data['width'] ?? null, $data['height'] ?? null);
    }

    /**
     * @return array{name: string, bandwidth: int, size_bytes: int, width: int|null, height: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'bandwidth' => $this->bandwidth,
            'size_bytes' => $this->sizeBytes,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
