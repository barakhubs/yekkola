<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use RuntimeException;

/**
 * The provider has no asset with this id (deleted, or not created yet). Not the same as a processing error.
 */
final class VideoAssetNotFound extends RuntimeException {}
