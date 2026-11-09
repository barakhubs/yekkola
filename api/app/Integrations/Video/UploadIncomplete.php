<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use RuntimeException;

/** The upload can't be assembled: parts missing, wrong ETags, or the upload expired. */
final class UploadIncomplete extends RuntimeException {}
