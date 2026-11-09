<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use RuntimeException;

/** The file can't be transcoded: not a video/audio file, corrupt, or FFmpeg failed. */
final class MediaProcessingFailed extends RuntimeException {}
