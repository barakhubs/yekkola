<?php

declare(strict_types=1);

namespace App\Integrations\Video;

final readonly class DirectUpload
{
    public function __construct(
        public string $uploadId,
        public string $url,
    ) {}
}
