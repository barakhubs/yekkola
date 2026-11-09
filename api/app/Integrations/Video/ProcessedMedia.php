<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** The result of processing: renditions, length, and the AES-128 key (raw 16 bytes) we keep encrypted. */
final readonly class ProcessedMedia
{
    /**
     * @param  list<Rendition>  $renditions  highest first
     */
    public function __construct(
        public array $renditions,
        public int $durationSeconds,
        public string $key,
        public string $outputPrefix,
        public bool $hasPoster,
    ) {}
}
