<?php

declare(strict_types=1);

namespace App\Domain\Protection\Stamping;

use RuntimeException;

/** The PDF can't be read by the stamper (encrypted, damaged, or a structure it doesn't support). */
final class DocumentNotStampable extends RuntimeException {}
