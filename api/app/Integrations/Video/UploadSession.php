<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** An open multipart upload: the browser sends `partCount` parts of `partSizeBytes` (the last one smaller). */
final readonly class UploadSession
{
    public function __construct(
        public string $uploadId,
        /** Where the source file is stored. */
        public string $sourceKey,
        public int $partSizeBytes,
        public int $partCount,
    ) {}

    /** At least `$minPartBytes` (S3: 5 MiB) per part, and never more than `$maxParts` parts (S3: 10,000). */
    public static function partSize(int $sizeBytes, int $preferredBytes, int $minPartBytes = 5 * 1024 * 1024, int $maxParts = 10_000): int
    {
        return max($preferredBytes, $minPartBytes, (int) ceil($sizeBytes / $maxParts));
    }
}
